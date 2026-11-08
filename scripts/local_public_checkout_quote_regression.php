<?php
/**
 * Guest checkout quote HTTP regression. Local kham_crm_api only.
 * All fixtures are rolled back, including prices and shipping rules.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Local XAMPP kham_crm_api database required.\n");
    exit(2);
}

$check = static function (bool $condition, string $description): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
};
$send = static function (array $payload) use ($kernel): array {
    $request = Request::create(
        'http://localhost/api/v1/storefront/checkout/quote',
        'POST', [], [], [],
        ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1'],
        json_encode($payload, JSON_THROW_ON_ERROR)
    );
    $response = $kernel->handle($request);
    try {
        return [$response->getStatusCode(), json_decode($response->getContent(), true),
            $response->headers->get('Cache-Control')];
    } finally {
        $kernel->terminate($request, $response);
    }
};

$before = [
    'orders' => DB::table('orders')->count(),
    'movements' => DB::table('inventory_movements')->count(),
    'rules' => DB::table('shipping_rules')->count(),
];
$exit = 0;
DB::beginTransaction();
try {
    $market = DB::table('markets')->where('code', 'AE')->firstOrFail();
    $warehouse = DB::table('warehouses')->where('code', 'EG-01')->firstOrFail();
    $stock = DB::table('inventory_stocks')->where('warehouse_id', $warehouse->id)
        ->whereRaw('(on_hand - reserved - damaged) >= 2')->first();
    if (!$stock) {
        throw new RuntimeException('Need at least 2 available Egypt warehouse units.');
    }

    $variant = DB::table('product_variants')->where('id', $stock->variant_id)->firstOrFail();
    DB::table('products')->where('id', $variant->product_id)->update(['status' => 'active']);
    DB::table('product_variants')->where('id', $variant->id)->update(['is_active' => 1]);

    DB::table('product_market_prices')->updateOrInsert(
        ['variant_id' => $variant->id, 'market_id' => $market->id],
        ['currency_id' => $market->currency_id, 'price' => 100, 'compare_at_price' => null,
            'starts_at' => null, 'ends_at' => null, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now()]
    );
    DB::table('shipping_rules')->updateOrInsert(
        ['market_id' => $market->id, 'city_key' => 'dubai'],
        ['shipping_fee' => 15, 'free_shipping_threshold' => 200,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );

    $payload = [
        'market_id' => $market->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_address' => ['country_code' => 'AE', 'city' => 'Dubai'],
    ];
    [$status, $quote, $cache] = $send($payload);
    $check($status === 200 && ($quote['code'] ?? '') === 'STOREFRONT_QUOTE',
        'Guest can request a quote without a CRM token');
    $check(($quote['data']['currency_code'] ?? '') === 'AED'
        && (float) ($quote['data']['subtotal'] ?? -1) === 100.0
        && (float) ($quote['data']['shipping']['shipping_total'] ?? -1) === 15.0,
        'Server calculates AED pricing and shipping');
    $check(!isset($quote['data']['shipping']['rule_id'])
        && ($quote['data']['is_final_payable_total'] ?? true) === false,
        'Internal rule ID hidden and estimate is not final payable');
    $check(str_contains((string) $cache, 'no-store'), 'Guest quote is not cached');

    $badPrice = $payload;
    $badPrice['items'][0]['unit_price'] = 0.01;
    [$status] = $send($badPrice);
    $check($status === 422, 'Guest cannot supply a price');

    $coupon = $payload;
    $coupon['coupon_code'] = 'TEST';
    [$status] = $send($coupon);
    $check($status === 422, 'Guest coupons are disabled pending safe coupon scoping');

    $wrongCountry = $payload;
    $wrongCountry['shipping_address']['country_code'] = 'SA';
    [$status] = $send($wrongCountry);
    $check($status === 422, 'Cross-market destination rejected');

    $duplicate = $payload;
    $duplicate['items'][] = $duplicate['items'][0];
    [$status] = $send($duplicate);
    $check($status === 422, 'Duplicate variant lines rejected');

    $oversell = $payload;
    $oversell['items'][0]['quantity'] = min(100, (int) $stock->on_hand + 1);
    if ($oversell['items'][0]['quantity'] > (int) $stock->on_hand - (int) $stock->reserved - (int) $stock->damaged) {
        [$status] = $send($oversell);
        $check($status === 422, 'Insufficient Egypt stock rejected');
    }

    $payload['items'][0]['quantity'] = 2;
    [$status, $quote] = $send($payload);
    $check($status === 200 && (float) ($quote['data']['shipping']['shipping_total'] ?? -1) === 0.0,
        'Free shipping applies at configured threshold');

    $check(DB::table('orders')->count() === $before['orders']
        && DB::table('inventory_movements')->count() === $before['movements'],
        'Quotes do not create orders or inventory movements');

    echo "ALL GUEST QUOTE TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}
$after = [
    'orders' => DB::table('orders')->count(),
    'movements' => DB::table('inventory_movements')->count(),
    'rules' => DB::table('shipping_rules')->count(),
];
if ($before !== $after) {
    fwrite(STDERR, "FAIL: Database counts changed after rollback.\n");
    $exit = 1;
} else {
    echo "PASS: Original orders, movements and shipping rules preserved.\n";
}
exit($exit);
