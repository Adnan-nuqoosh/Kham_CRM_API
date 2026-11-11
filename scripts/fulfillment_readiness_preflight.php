<?php
/**
 * READ-ONLY pre-deployment audit for Egypt-central fulfillment.
 *
 * php scripts/fulfillment_readiness_preflight.php
 *
 * This script never migrates, seeds, creates, updates or deletes records.
 * It prints counts and statuses only (no credentials or customer information).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$blockers = 0;
$warnings = 0;
$check = static function (bool $ok, string $description) use (&$blockers): void {
    echo ($ok ? 'PASS: ' : 'BLOCKER: ') . $description . PHP_EOL;
    if (!$ok) ++$blockers;
};
$warn = static function (bool $condition, string $description) use (&$warnings): void {
    if ($condition) {
        echo 'WARNING: ' . $description . PHP_EOL;
        ++$warnings;
    }
};

try {
    $connection = DB::connection();
    $driver = $connection->getDriverName();
    echo 'Environment: ' . app()->environment() . PHP_EOL;
    echo 'Database driver: ' . $driver . PHP_EOL;
    $check($driver === 'mysql', 'MySQL database connection');
    if ($driver !== 'mysql') exit(2);

    $requiredTables = [
        'markets', 'currencies', 'warehouses', 'inventory_stocks',
        'inventory_movements', 'orders', 'order_items', 'migrations',
        'order_number_sequences',
    ];
    foreach ($requiredTables as $table) {
        $check(Schema::hasTable($table), 'Required table exists: ' . $table);
    }
    if ($blockers > 0) {
        echo "STOP: Missing tables; check migrations BEFORE attempting deployment.\n";
        exit(1);
    }

    $migration = '2026_11_08_000001_create_order_number_sequences';
    $check(DB::table('migrations')->where('migration', $migration)->exists(),
        'Order-number sequence migration is recorded as applied');

    $warehouseCode = (string) config('commerce.fulfillment_warehouse_code', 'EG-01');
    $warehouse = DB::table('warehouses as w')
        ->join('markets as m', 'm.id', '=', 'w.market_id')
        ->where('w.code', $warehouseCode)
        ->first(['w.id', 'w.is_active', 'm.country_code', 'm.is_active as market_active']);
    $check($warehouse !== null, 'Configured fulfillment warehouse code exists: ' . $warehouseCode);
    if ($warehouse) {
        $check((bool) $warehouse->is_active && (bool) $warehouse->market_active
            && strtoupper((string) $warehouse->country_code) === 'EG',
            'Configured fulfillment warehouse is active and belongs to Egypt');
    }

    foreach (['EG' => 'EGP', 'AE' => 'AED', 'SA' => 'SAR'] as $marketCode => $currencyCode) {
        $market = DB::table('markets as m')
            ->join('currencies as c', 'c.id', '=', 'm.currency_id')
            ->where('m.code', $marketCode)
            ->first(['m.is_active', 'c.code as currency', 'c.is_active as currency_active']);
        $check($market !== null && (bool) $market->is_active && (bool) $market->currency_active
            && $market->currency === $currencyCode,
            $marketCode . ' sales market active with expected ' . $currencyCode . ' currency');
    }

    $invalidStock = DB::table('inventory_stocks')
        ->whereRaw('reserved + damaged > on_hand')
        ->count();
    $check($invalidStock === 0, 'No inventory rows have reserved + damaged greater than on-hand');
    echo 'INFO: Inventory rows: ' . DB::table('inventory_stocks')->count() . PHP_EOL;
    echo 'INFO: Orders: ' . DB::table('orders')->count() . PHP_EOL;

    if ($warehouse) {
        $otherWarehouseOrders = DB::table('orders')->where('warehouse_id', '<>', $warehouse->id)->count();
        $warn($otherWarehouseOrders > 0,
            $otherWarehouseOrders . ' historical orders use another warehouse. Do not rewrite them automatically.');
        $otherWarehouseStock = DB::table('inventory_stocks')
            ->where('warehouse_id', '<>', $warehouse->id)
            ->where('on_hand', '>', 0)->count();
        $warn($otherWarehouseStock > 0,
            $otherWarehouseStock . ' stock rows outside Egypt have positive on-hand balances; review migration/operations policy.');
    }

    $currentYear = (int) now()->format('Y');
    $highestOrderNumber = 0;
    DB::table('orders')->where('order_number', 'like', 'KH-' . $currentYear . '-%')
        ->select('id', 'order_number')->orderBy('id')
        ->chunkById(500, function ($orders) use (&$highestOrderNumber, $currentYear): void {
            foreach ($orders as $order) {
                if (preg_match('/^KH-' . $currentYear . '-([0-9]+)$/', (string) $order->order_number, $match)) {
                    $highestOrderNumber = max($highestOrderNumber, (int) $match[1]);
                }
            }
        });
    $sequence = DB::table('order_number_sequences')->where('year', $currentYear)->value('last_number');
    $check($highestOrderNumber <= (int) ($sequence ?? 0),
        'Current-year order-number sequence is not behind existing orders');

    $engines = DB::select(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('orders','inventory_stocks','order_number_sequences')"
    );
    foreach ($engines as $table) {
        $check(strtoupper((string) $table->ENGINE) === 'INNODB',
            $table->TABLE_NAME . ' uses InnoDB transactional locking');
    }

    $warn(app()->environment('production') && (bool) config('app.debug'),
        'APP_DEBUG is enabled in production; disable before launch.');
    echo "NOTE: Shipping and tax amounts still need approved server-side rules for public storefront checkout.\n";
    echo 'SUMMARY: ' . $blockers . ' blockers, ' . $warnings . ' warnings.' . PHP_EOL;
    exit($blockers === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'PREFLIGHT FAILED: ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(2);
}
