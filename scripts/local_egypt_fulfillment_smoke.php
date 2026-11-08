<?php
/**
 * Run only against the local XAMPP test DB:
 *   php scripts/local_egypt_fulfillment_smoke.php
 *
 * All temporary prices, orders and inventory movements are rolled back.
 * Never run on a production or remote database.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\OrderStatus;
use App\Models\Market;
use App\Models\ProductMarketPrice;
use App\Models\Warehouse;
use App\Services\FulfillmentWarehouseResolver;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$host = strtolower((string) config('database.connections.mysql.host', ''));
$db = (string) DB::connection()->getDatabaseName();
if (app()->environment() !== 'local' || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || $db !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: This smoke test requires APP_ENV=local, local MySQL and database kham_crm_api.\n");
    exit(2);
}

$check = function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException("FAIL: {$message}");
    echo "PASS: {$message}\n";
};
$warehouse = Warehouse::where('code', 'EG-01')->firstOrFail();
$stock = DB::table('inventory_stocks')->where('warehouse_id', $warehouse->id)
    ->whereRaw('on_hand - reserved >= 4')->orderBy('variant_id')->first();
if (!$stock) {
    fwrite(STDERR, "STOP: Egypt warehouse needs a variant with at least 4 available units.\n");
    exit(2);
}
$variantId = (int) $stock->variant_id;
$initialOnHand = (int) $stock->on_hand;
$initialReserved = (int) $stock->reserved;
$state = fn () => DB::table('inventory_stocks')->where('warehouse_id', $warehouse->id)
    ->where('variant_id', $variantId)->first();
$exitCode = 0;

DB::beginTransaction();
try {
    $markets = [];
    foreach (['EG', 'AE', 'SA'] as $code) {
        $market = Market::with('currency')->where('code', $code)->firstOrFail();
        $check((bool) $market->currency, "{$code} market has a currency");
        $resolved = app(FulfillmentWarehouseResolver::class)->forMarket($market->id);
        $check($resolved->id === $warehouse->id, "{$code} resolves to Egypt warehouse EG-01");
        $markets[$code] = $market;
    }

    // Temporary prices exist only within this outer transaction.
    foreach (['EG' => '275.0000', 'AE' => '25.0000', 'SA' => '30.0000'] as $code => $amount) {
        $market = $markets[$code];
        ProductMarketPrice::updateOrCreate(
            ['variant_id' => $variantId, 'market_id' => $market->id],
            ['currency_id' => $market->currency_id, 'price' => $amount,
                'compare_at_price' => null, 'starts_at' => null, 'ends_at' => null, 'is_active' => true]
        );
    }

    $create = function (string $code, int $qty) use ($markets, $variantId) {
        return app(OrderService::class)->create([
            'market_id' => $markets[$code]->id,
            // Legacy frontend input must be ignored.
            'warehouse_id' => $code === 'AE' ? 2 : ($code === 'SA' ? 3 : 1),
            'items' => [['variant_id' => $variantId, 'quantity' => $qty]],
            'shipping_address' => ['first_name' => 'Local Test', 'line1' => 'Test Address',
                'city' => 'Test City', 'country_code' => $code],
        ]);
    };
    $status = app(OrderStatusService::class);

    $uae = $create('AE', 2);
    $check($uae->warehouse_id === $warehouse->id && $uae->currency_code === 'AED',
        'UAE order uses Egypt warehouse and AED');
    $check((int) $state()->on_hand === $initialOnHand
        && (int) $state()->reserved === $initialReserved + 2,
        'UAE order reserves 2 without reducing on-hand');

    $status->transition($uae, OrderStatus::Shipped);
    $check((int) $state()->on_hand === $initialOnHand - 2
        && (int) $state()->reserved === $initialReserved,
        'Shipping deducts exactly 2 units');
    $status->transition($uae, OrderStatus::Shipped);
    $check((int) $state()->on_hand === $initialOnHand - 2,
        'Repeated shipped status does not deduct stock again');
    $status->transition($uae, OrderStatus::Delivered);
    $check((int) $state()->on_hand === $initialOnHand - 2,
        'Delivery does not deduct stock again');

    try {
        $status->transition($uae, OrderStatus::Processing);
        throw new RuntimeException('Invalid delivered-to-processing transition was allowed');
    } catch (ValidationException $e) {
        echo "PASS: Invalid backwards transition rejected\n";
    }

    $ksa = $create('SA', 1);
    $check($ksa->warehouse_id === $warehouse->id && $ksa->currency_code === 'SAR',
        'KSA order uses Egypt warehouse and SAR');
    $status->transition($ksa, OrderStatus::Cancelled);
    $check((int) $state()->reserved === $initialReserved
        && (int) $state()->on_hand === $initialOnHand - 2,
        'KSA cancellation releases reserved stock');

    $egypt = $create('EG', 1);
    $check($egypt->warehouse_id === $warehouse->id && $egypt->currency_code === 'EGP',
        'Egypt order uses Egypt warehouse and EGP');
    $status->transition($egypt, OrderStatus::Cancelled);
    $check((int) $state()->reserved === $initialReserved,
        'Egypt cancellation releases reserved stock');

    echo "ALL LOCAL SMOKE TESTS PASSED; rolling back all temporary changes.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
} finally {
    DB::rollBack();
}
$after = $state();
if ((int) $after->on_hand !== $initialOnHand || (int) $after->reserved !== $initialReserved) {
    fwrite(STDERR, "WARNING: Stock balance differs after rollback; investigate before further tests.\n");
    $exitCode = 1;
} else {
    echo "PASS: Original Egypt stock balance preserved after rollback\n";
}
exit($exitCode);
