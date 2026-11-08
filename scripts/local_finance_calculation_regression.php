<?php
/**
 * Finance report arithmetic and market-isolation regression.
 *
 * Run: php scripts/local_finance_calculation_regression.php
 * Uses rollback-only fixtures on the dedicated local XAMPP database.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

$host = strtolower((string) config('database.connections.mysql.host', ''));
$dbName = (string) DB::connection()->getDatabaseName();
if (app()->environment() !== 'local'
    || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || $dbName !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and localhost MySQL database kham_crm_api.\n");
    exit(2);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};
$equalMoney = static fn (mixed $actual, float $expected): bool =>
    $actual !== null && abs((float) $actual - $expected) < 0.0001;

$send = static function (string $path) use ($kernel): array {
    $request = Request::create('http://localhost' . $path, 'GET', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $response = $kernel->handle($request);
    try {
        $body = $response->getContent();
        return [$response->getStatusCode(), is_string($body) ? json_decode($body, true) : null];
    } finally {
        $kernel->terminate($request, $response);
    }
};

$ae = DB::table('markets')->where('code', 'AE')->where('is_active', 1)->first();
$eg = DB::table('markets')->where('code', 'EG')->where('is_active', 1)->first();
$warehouseId = DB::table('warehouses')->where('code', 'EG-01')->where('is_active', 1)->value('id');
if (!$ae || !$eg || !$warehouseId) {
    fwrite(STDERR, "STOP: Active AE/EG markets and EG-01 warehouse are required.\n");
    exit(2);
}
$tables = ['users', 'model_has_permissions', 'orders', 'order_items', 'finance_expenses'];
$snapshot = static function () use ($tables): array {
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    return $counts;
};
$before = $snapshot();
$exitCode = 0;

DB::beginTransaction();
try {
    $user = User::create([
        'name' => 'Finance Arithmetic Regression',
        'email' => 'finance-math-' . Str::random(16) . '@example.invalid',
        'password' => Str::random(48),
    ]);
    $user->givePermissionTo('finance.view');
    Sanctum::actingAs($user, ['*']);

    $createOrder = static function (int $marketId, string $currency, float $total)
        use ($warehouseId): int {
        return DB::table('orders')->insertGetId([
            'order_number' => 'FIN-MATH-' . Str::upper(Str::random(18)),
            'market_id' => $marketId,
            'warehouse_id' => $warehouseId,
            'currency_code' => $currency,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'fulfillment_status' => 'delivered',
            'subtotal' => $total,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => $total,
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $orderId = $createOrder((int) $ae->id, 'AED', 195);
    DB::table('orders')->where('id', $orderId)->update([
        'subtotal' => 200, 'discount_total' => 20,
        'shipping_total' => 10, 'tax_total' => 5,
    ]);
    DB::table('order_items')->insert([
        [
            'order_id' => $orderId, 'sku' => 'FIN-A', 'name' => 'Test product A',
            'quantity' => 2, 'unit_price' => 60, 'line_total' => 120,
            'unit_cost_snapshot' => 30, 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'order_id' => $orderId, 'sku' => 'FIN-B', 'name' => 'Test product B',
            'quantity' => 1, 'unit_price' => 80, 'line_total' => 80,
            'unit_cost_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    $missingItemId = DB::table('order_items')->where('order_id', $orderId)
        ->where('sku', 'FIN-B')->value('id');

    DB::table('finance_expenses')->insert([
        'market_id' => $ae->id, 'currency_code' => 'AED', 'category' => 'QA',
        'description' => 'Temporary finance calculation fixture', 'amount' => 18.75,
        'incurred_on' => now()->toDateString(), 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $egOrderId = $createOrder((int) $eg->id, 'EGP', 1000);
    DB::table('order_items')->insert([
        'order_id' => $egOrderId, 'sku' => 'FIN-EG', 'name' => 'Other market fixture',
        'quantity' => 1, 'unit_price' => 1000, 'line_total' => 1000,
        'unit_cost_snapshot' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('finance_expenses')->insert([
        'market_id' => $eg->id, 'currency_code' => 'EGP', 'category' => 'QA',
        'description' => 'Other market expense fixture', 'amount' => 300,
        'incurred_on' => now()->toDateString(), 'created_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $path = '/api/v1/finance/report?period=daily&market_id=' . $ae->id;
    [$status, $response] = $send($path);
    $data = $response['data'] ?? [];
    $assert($status === 200 && ($data['currency_code'] ?? null) === 'AED',
        'AED report succeeds without mixing EGP');
    $assert(($data['missing_cost_items'] ?? null) === 1,
        'Missing historical cost is detected');
    // Null coalescing (??) treats an existing null as absent; use
    // array_key_exists to distinguish an intentional null profit from
    // a missing response field.
    $assert(array_key_exists('gross_profit', $data)
        && $data['gross_profit'] === null
        && array_key_exists('net_profit', $data)
        && $data['net_profit'] === null
        && ($data['profit_status'] ?? null) === 'incomplete_cost_data',
        'Profit is withheld when a delivered item has no cost');
    $assert($equalMoney($data['known_cost_of_goods'] ?? null, 60),
        'Known COGS includes only costed items');

    DB::table('order_items')->where('id', $missingItemId)->update([
        'unit_cost_snapshot' => 15,
    ]);
    [$status, $response] = $send($path);
    $data = $response['data'] ?? [];
    $assert($status === 200 && ($data['profit_status'] ?? null) === 'provisional',
        'Complete cost snapshots enable provisional profit');
    $assert(($data['total_orders'] ?? null) === 1
        && ($data['delivered_orders'] ?? null) === 1,
        'Order counts exclude the other market');
    $assert($equalMoney($data['delivered_sales'] ?? null, 195)
        && $equalMoney($data['paid_delivered_sales'] ?? null, 195),
        'Sales and paid sales match delivered AED order');
    $assert($equalMoney($data['known_cost_of_goods'] ?? null, 75),
        'COGS equals quantity times historical unit cost');
    $assert($equalMoney($data['operating_expenses'] ?? null, 18.75),
        'Only UAE operating expenses are included');
    $assert($equalMoney($data['gross_profit'] ?? null, 120)
        && $equalMoney($data['net_profit'] ?? null, 101.25),
        'Provisional gross and net arithmetic matches current reporting definition');

    [$status, $response] = $send('/api/v1/finance/report?period=daily&market_id=' . $eg->id);
    $egData = $response['data'] ?? [];
    $assert($status === 200 && ($egData['currency_code'] ?? null) === 'EGP'
        && $equalMoney($egData['delivered_sales'] ?? null, 1000)
        && $equalMoney($egData['operating_expenses'] ?? null, 300),
        'Egypt reporting remains separate from UAE reporting');

    echo "ALL LOCAL FINANCE CALCULATION TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}

$after = $snapshot();
if ($after !== $before) {
    fwrite(STDERR, 'FAIL: Database row counts changed after rollback.\n');
    fwrite(STDERR, 'Before: ' . json_encode($before, JSON_THROW_ON_ERROR) . PHP_EOL);
    fwrite(STDERR, 'After: ' . json_encode($after, JSON_THROW_ON_ERROR) . PHP_EOL);
    $exitCode = 1;
} else {
    echo "PASS: Original users, permissions, orders, items and expenses preserved after rollback\n";
}
exit($exitCode);
