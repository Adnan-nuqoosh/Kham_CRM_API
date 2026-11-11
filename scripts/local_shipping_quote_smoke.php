<?php
/**
 * Local, rollback-only shipping quote test.
 * Run after migration: php scripts/local_shipping_quote_smoke.php
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
if (!Schema::hasTable('shipping_rules')) {
    fwrite(STDERR, "STOP: Run php artisan migrate first.\n");
    exit(2);
}
function verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$send = static function (string $method, string $path, array $body = []) use ($kernel): array {
    $request = Request::create('http://localhost' . $path, $method, [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    ], json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    try {
        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    } finally {
        $kernel->terminate($request, $response);
    }
};
$counts = [
    'orders' => DB::table('orders')->count(),
    'shipping_rules' => DB::table('shipping_rules')->count(),
];
$exitCode = 0;
DB::beginTransaction();
try {
    $market = DB::table('markets')->where('code', 'AE')->first();
    verify($market !== null, 'UAE market exists');
    $product = DB::table('products')->insertGetId([
        'name' => 'Shipping Quote Test', 'slug' => 'shipping-quote-' . Str::lower(Str::random(12)),
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $variant = DB::table('product_variants')->insertGetId([
        'product_id' => $product, 'sku' => 'SHIP-TEST-' . Str::upper(Str::random(12)),
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('product_market_prices')->insert([
        'variant_id' => $variant, 'market_id' => $market->id,
        'currency_id' => $market->currency_id, 'price' => 100,
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = User::create([
        'name' => 'Shipping Test', 'email' => 'shipping-' . Str::random(14) . '@example.invalid',
        'password' => Str::random(40),
    ]);
    $user->givePermissionTo('orders.manage', 'settings.manage');
    Sanctum::actingAs($user, ['*']);
    $payload = [
        'market_id' => $market->id,
        'items' => [['variant_id' => $variant, 'quantity' => 1, 'unit_price' => 0.01]],
        'shipping_address' => ['country_code' => 'AE', 'city' => 'Dubai'],
    ];
    [$status] = $send('POST', '/api/v1/checkout/quote', $payload);
    verify($status === 422, 'Missing shipping configuration fails closed');

    [$status, $rule] = $send('POST', '/api/v1/shipping-rules', [
        'market_id' => $market->id, 'city' => 'Dubai', 'shipping_fee' => 15,
        'free_shipping_threshold' => 200,
    ]);
    verify($status === 201 && ($rule['data']['is_active'] ?? null) === false,
        'CRM creates shipping rule inactive by default');
    $ruleId = $rule['data']['id'] ?? 0;
    [$status] = $send('POST', '/api/v1/checkout/quote', $payload);
    verify($status === 422, 'Inactive rule cannot be used for checkout');

    [$status] = $send('PATCH', '/api/v1/shipping-rules/' . $ruleId, ['is_active' => true]);
    verify($status === 200, 'CRM can activate approved shipping rule');
    [$status, $quote] = $send('POST', '/api/v1/checkout/quote', $payload);
    if ($status !== 200) {
        throw new RuntimeException('Checkout quote failed with HTTP ' . $status
            . ': ' . json_encode([
                'code' => $quote['code'] ?? null,
                'message' => $quote['message'] ?? null,
                'errors' => $quote['errors'] ?? null,
            ], JSON_UNESCAPED_SLASHES));
    }
    verify($status === 200 && ($quote['data']['currency_code'] ?? '') === 'AED'
        && (float) ($quote['data']['subtotal'] ?? 0) === 100.0
        && (float) ($quote['data']['shipping']['shipping_total'] ?? 0) === 15.0,
        'Server quote ignores client price and applies AED 15 test shipping');
    verify(($quote['data']['tax_status'] ?? '') === 'not_configured'
        && ($quote['data']['is_final_payable_total'] ?? true) === false,
        'Quote clearly discloses missing tax configuration');

    $payload['items'][0]['quantity'] = 2;
    [$status, $quote] = $send('POST', '/api/v1/checkout/quote', $payload);
    verify($status === 200 && (float) ($quote['data']['shipping']['shipping_total'] ?? -1) === 0.0,
        'Free shipping threshold calculated server-side');

    $payload['shipping_address']['country_code'] = 'SA';
    [$status] = $send('POST', '/api/v1/checkout/quote', $payload);
    verify($status === 422, 'Mismatched shipping country rejected');
    verify(DB::table('orders')->count() === $counts['orders'], 'Quotes never create orders');
    echo "ALL LOCAL SHIPPING QUOTE TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}
if (DB::table('orders')->count() !== $counts['orders']
    || DB::table('shipping_rules')->count() !== $counts['shipping_rules']) {
    fwrite(STDERR, "FAIL: Record counts changed after rollback.\n");
    $exitCode = 1;
} else {
    echo "PASS: Original order and shipping-rule counts preserved.\n";
}
exit($exitCode);
