<?php
/**
 * Rollback-only HTTP API integration test (actual Laravel routing/middleware).
 * Run: php scripts/local_orders_api_regression.php
 * Local XAMPP kham_crm_api ONLY. No persistent test orders, users or prices.
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
if (app()->environment() !== 'local' || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || $dbName !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: APP_ENV=local and localhost MySQL kham_crm_api required.\n");
    exit(2);
}
function apiCheck(bool $ok, string $description): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . PHP_EOL;
}
$send = static function (string $method, string $path, ?array $body = null) use ($kernel): array {
    $request = Request::create('http://localhost' . $path, $method, [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    try {
        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    } finally {
        $kernel->terminate($request, $response);
    }
};
$original = [
    'orders' => DB::table('orders')->count(),
    'users' => DB::table('users')->count(),
    'movements' => DB::table('inventory_movements')->count(),
];
$warehouse = DB::table('warehouses')->where('code', 'EG-01')->first();
if (!$warehouse) {
    fwrite(STDERR, "STOP: Egypt warehouse EG-01 is missing.\n");
    exit(2);
}
$stock = DB::table('inventory_stocks')->where('warehouse_id', $warehouse->id)
    ->whereRaw('(on_hand - reserved - damaged) >= 3')->orderBy('variant_id')->first();
if (!$stock) {
    fwrite(STDERR, "STOP: Need a variant with at least 3 sellable units in Egypt.\n");
    exit(2);
}
$initialStock = ['on_hand' => (int) $stock->on_hand, 'reserved' => (int) $stock->reserved];
$state = static fn () => DB::table('inventory_stocks')
    ->where('variant_id', $stock->variant_id)->where('warehouse_id', $warehouse->id)->first();

$exitCode = 0;
DB::beginTransaction();
try {
    $user = User::create([
        'name' => 'Local API Regression',
        'email' => 'api-regression-' . Str::random(18) . '@example.invalid',
        'password' => Str::random(50),
    ]);
    $user->givePermissionTo('orders.view', 'orders.manage', 'inventory.view');
    Sanctum::actingAs($user, ['*']);

    $markets = DB::table('markets')->whereIn('code', ['AE', 'SA'])->get()->keyBy('code');
    apiCheck($markets->count() === 2, 'UAE and KSA sales markets configured');
    foreach (['AE' => 25, 'SA' => 30] as $code => $price) {
        DB::table('product_market_prices')->updateOrInsert(
            ['variant_id' => $stock->variant_id, 'market_id' => $markets[$code]->id],
            ['currency_id' => $markets[$code]->currency_id, 'price' => $price,
                'compare_at_price' => null, 'starts_at' => null, 'ends_at' => null,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
    }
    // Product cost is explicitly labelled EGP. Never convert it into AED/SAR
    // without an approved historical FX policy.
    DB::table('product_variants')->where('id', $stock->variant_id)->update([
        'cost_price' => 12.50,
        'cost_currency_code' => 'EGP',
    ]);
    $place = static function (string $marketCode, int $requestedWarehouse) use ($send, $markets, $stock): array {
        return $send('POST', '/api/v1/orders', [
            'market_id' => $markets[$marketCode]->id,
            'warehouse_id' => $requestedWarehouse,
            'items' => [['variant_id' => $stock->variant_id, 'quantity' => 1, 'unit_price' => 0.01]],
            'shipping_address' => ['first_name' => 'Test', 'line1' => 'Local Test Address',
                'city' => 'Test City', 'country_code' => $marketCode],
        ]);
    };

    [$status, $uae] = $place('AE', 999999);
    apiCheck($status === 422, 'Invalid client-supplied warehouse ID fails request validation');

    [$status, $uae] = $place('AE', $warehouse->id);
    apiCheck($status === 201 && ($uae['code'] ?? '') === 'ORDER_CREATED', 'UAE order created through authenticated API');
    $uaeId = (int) ($uae['data']['id'] ?? 0);
    apiCheck($uaeId > 0 && (int) ($uae['data']['warehouse_id'] ?? 0) === (int) $warehouse->id
        && ($uae['data']['currency_code'] ?? '') === 'AED', 'UAE API order uses Egypt warehouse and AED');
    apiCheck((float) ($uae['data']['items'][0]['unit_price'] ?? 0) === 25.0,
        'Client-supplied unit price ignored in favor of CRM market price');
    apiCheck((float) ($uae['data']['items'][0]['unit_cost_snapshot'] ?? -1) === 12.50
        && ($uae['data']['items'][0]['unit_cost_currency_code'] ?? '') === 'EGP',
        'UAE order snapshots Egypt cost with original EGP currency, not AED');
    DB::table('product_variants')->where('id', $stock->variant_id)->update([
        'cost_price' => 99.00,
    ]);
    apiCheck((float) DB::table('order_items')->where('order_id', $uaeId)
        ->value('unit_cost_snapshot') === 12.50,
        'Historical cost snapshot stays unchanged after product cost update');

    [$status, $order] = $send('GET', '/api/v1/orders/' . $uaeId);
    apiCheck($status === 200 && (int) ($order['data']['id'] ?? 0) === $uaeId,
        'GET order detail returns created order');

    [$status, $result] = $send('PATCH', '/api/v1/orders/' . $uaeId . '/status', ['status' => 'shipped']);
    apiCheck($status === 200 && ($result['data']['status'] ?? '') === 'shipped',
        'PATCH shipped transitions order and commits sale');
    apiCheck((int) $state()->on_hand === $initialStock['on_hand'] - 1
        && (int) $state()->reserved === $initialStock['reserved'],
        'Shipment decrements Egypt stock exactly once');

    [$status, $result] = $send('PATCH', '/api/v1/orders/' . $uaeId . '/status', ['status' => 'shipped']);
    apiCheck($status === 200 && (int) $state()->on_hand === $initialStock['on_hand'] - 1,
        'Repeated shipped API request does not double-deduct');

    [$status, $result] = $send('PATCH', '/api/v1/orders/' . $uaeId . '/status', ['status' => 'delivered']);
    apiCheck($status === 200 && ($result['data']['status'] ?? '') === 'delivered',
        'Delivered status succeeds without further stock deduction');

    [$status, $result] = $send('PATCH', '/api/v1/orders/' . $uaeId . '/status', ['status' => 'processing']);
    apiCheck($status === 422, 'Invalid backwards order status rejected by API');

    // Unknown source currency must not be treated as an order-currency cost.
    DB::table('product_variants')->where('id', $stock->variant_id)->update([
        'cost_price' => 99.00,
        'cost_currency_code' => null,
    ]);
    [$status, $ksa] = $place('SA', $warehouse->id);
    apiCheck($status === 201 && ($ksa['data']['currency_code'] ?? '') === 'SAR',
        'KSA API order created with SAR and shared Egypt warehouse');
    $ksaId = (int) ($ksa['data']['id'] ?? 0);
    apiCheck(($ksa['data']['items'][0]['unit_cost_snapshot'] ?? null) === null
        && ($ksa['data']['items'][0]['unit_cost_currency_code'] ?? null) === null,
        'Unverified product cost currency leaves order cost incomplete');
    apiCheck((int) $state()->reserved === $initialStock['reserved'] + 1,
        'KSA order reserves one unit of Egypt stock');
    [$status, $result] = $send('PATCH', '/api/v1/orders/' . $ksaId . '/status', ['status' => 'cancelled']);
    apiCheck($status === 200 && ($result['data']['status'] ?? '') === 'cancelled'
        && (int) $state()->reserved === $initialStock['reserved'],
        'KSA cancellation releases reservation through API');

    [$status, $result] = $send('GET', '/api/v1/inventory/movements?variant_id=' . $stock->variant_id);
    apiCheck($status === 200, 'Inventory movements API remains accessible');

    [$status, $result] = $send('GET', '/api/v1/orders?market_id=' . $markets['AE']->id);
    apiCheck($status === 200, 'Market-filtered orders list API remains accessible');

    echo "ALL LOCAL HTTP API REGRESSION TESTS PASSED; rolling back changes.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}
$after = [
    'orders' => DB::table('orders')->count(),
    'users' => DB::table('users')->count(),
    'movements' => DB::table('inventory_movements')->count(),
];
$finalStock = $state();
if ($after !== $original || (int) $finalStock->on_hand !== $initialStock['on_hand']
    || (int) $finalStock->reserved !== $initialStock['reserved']) {
    fwrite(STDERR, "FAIL: Database counts or stock differ after rollback. Investigate.\n");
    $exitCode = 1;
} else {
    echo "PASS: Original orders, users, movements and stock preserved after rollback\n";
}
exit($exitCode);
