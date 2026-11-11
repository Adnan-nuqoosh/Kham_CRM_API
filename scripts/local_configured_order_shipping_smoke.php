<?php
/**
 * Rollback-only configured CRM order shipping regression.
 * Run locally: php scripts/local_configured_order_shipping_smoke.php
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: local XAMPP kham_crm_api only.\n");
    exit(2);
}
if (!Schema::hasColumn('orders', 'shipping_rule_id')) {
    fwrite(STDERR, "STOP: Run php artisan migrate first.\n");
    exit(2);
}
function checkConfigured(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$send = static function (array $payload) use ($kernel): array {
    $request = Request::create('http://localhost/api/v1/orders', 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    try {
        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    } finally {
        $kernel->terminate($request, $response);
    }
};
$original = [
    'orders' => DB::table('orders')->count(),
    'rules' => DB::table('shipping_rules')->count(),
    'movements' => DB::table('inventory_movements')->count(),
];
$exitCode = 0;
DB::beginTransaction();
try {
    $market = DB::table('markets')->where('code', 'AE')->firstOrFail();
    $warehouse = DB::table('warehouses')->where('code', 'EG-01')->firstOrFail();
    $stock = DB::table('inventory_stocks')->where('warehouse_id', $warehouse->id)
        ->whereRaw('on_hand - reserved - damaged >= 2')->first();
    if (!$stock) throw new RuntimeException('Need at least two available Egypt units.');
    $beforeReserved = (int) $stock->reserved;
    $variantId = (int) $stock->variant_id;
    DB::table('product_market_prices')->updateOrInsert(
        ['variant_id' => $variantId, 'market_id' => $market->id],
        ['currency_id' => $market->currency_id, 'price' => 100, 'compare_at_price' => null,
            'starts_at' => null, 'ends_at' => null, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now()]
    );
    DB::table('shipping_rules')->updateOrInsert(
        ['market_id' => $market->id, 'city_key' => 'dubai'],
        ['shipping_fee' => 15, 'free_shipping_threshold' => 200,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
    );
    $ruleId = (int) DB::table('shipping_rules')->where('market_id', $market->id)
        ->where('city_key', 'dubai')->value('id');
    $user = User::create([
        'name' => 'Configured Shipping Test',
        'email' => 'configured-shipping-' . Str::random(16) . '@example.invalid',
        'password' => Str::random(40),
    ]);
    $user->givePermissionTo('orders.manage');
    Sanctum::actingAs($user, ['*']);
    $payload = [
        'market_id' => $market->id,
        'shipping_mode' => 'configured',
        'tax_total' => 0, // Test fixture only, NOT an approved tax rate.
        'items' => [['variant_id' => $variantId, 'quantity' => 1, 'unit_price' => 0.01]],
        'shipping_address' => [
            'first_name' => 'Test', 'line1' => 'Test Address',
            'city' => 'Dubai', 'country_code' => 'AE',
        ],
    ];

    // Rollback-only approved zero-rate tax fixture for configured-order integration.
    // This is NOT approval of any real market tax policy.
    $approverId = DB::table('users')->orderBy('id')->value('id');
    if (!$approverId) throw new RuntimeException('STOP: local user required for test tax fixture.');
    DB::table('market_tax_rules')->updateOrInsert(
        ['market_id' => $market->id],
        ['rate_percent' => 0, 'calculation_method' => 'exclusive',
            'tax_shipping' => false, 'is_approved' => true,
            'approved_at' => now(), 'approved_by' => $approverId,
            'updated_at' => now()]
    );
    config()->set('commerce.configured_shipping_orders_enabled', false);
    [$status] = $send($payload);
    checkConfigured($status === 422, 'Configured orders are disabled by default');
    config()->set('commerce.configured_shipping_orders_enabled', true);

    [$status] = $send(array_merge($payload, ['shipping_total' => 0]));
    checkConfigured($status === 422, 'Client cannot override configured shipping fee');

    $wrongCountry = $payload;
    $wrongCountry['shipping_address']['country_code'] = 'SA';
    [$status] = $send($wrongCountry);
    checkConfigured($status === 422, 'Country mismatch rejected for configured order');

    [$status, $order] = $send($payload);
    if ($status !== 201) {
        throw new RuntimeException('Order failed HTTP ' . $status . ': '
            . json_encode(['code' => $order['code'] ?? null, 'errors' => $order['errors'] ?? null]));
    }
    $data = $order['data'] ?? [];
    checkConfigured(($data['shipping_mode'] ?? null) === 'configured'
        && (int) ($data['shipping_rule_id'] ?? 0) === $ruleId
        && (float) ($data['shipping_total'] ?? -1) === 15.0
        && (float) ($data['grand_total'] ?? -1) === 115.0,
        'Server calculates AED 15 shipping and records applied rule');
    checkConfigured((int) ($data['warehouse_id'] ?? 0) === (int) $warehouse->id
        && ($data['currency_code'] ?? '') === 'AED',
        'Order still fulfills from Egypt and charges AED');
    $reserved = DB::table('inventory_stocks')->where('variant_id', $variantId)
        ->where('warehouse_id', $warehouse->id)->value('reserved');
    checkConfigured((int) $reserved === $beforeReserved + 1,
        'Configured order reserves exactly one Egypt unit');

    $payload['items'][0]['quantity'] = 2;
    [$status, $order] = $send($payload);
    if ($status !== 201) {
        throw new RuntimeException('Free shipping order failed HTTP ' . $status . ': '
            . json_encode(['code' => $order['code'] ?? null, 'errors' => $order['errors'] ?? null]));
    }
    checkConfigured((float) ($order['data']['shipping_total'] ?? -1) === 0.0
        && (float) ($order['data']['grand_total'] ?? -1) === 200.0,
        'Configured order receives free shipping at approved test threshold');

    echo "ALL CONFIGURED ORDER SHIPPING TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}
if (DB::table('orders')->count() !== $original['orders']
    || DB::table('shipping_rules')->count() !== $original['rules']
    || DB::table('inventory_movements')->count() !== $original['movements']) {
    fwrite(STDERR, "FAIL: Original record counts changed after rollback.\n");
    $exitCode = 1;
} else {
    echo "PASS: Original orders, shipping rules and inventory movements preserved.\n";
}
exit($exitCode);
