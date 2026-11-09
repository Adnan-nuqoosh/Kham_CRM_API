<?php
/**
 * Full local Laravel HTTP route regression for signed MyFatoorah V2 callbacks.
 * Uses synthetic events + Http::fake(); all DB writes rolled back.
 * Run: php scripts/local_myfatoorah_webhook_http_regression.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Market;
use App\Services\OrderService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Only local XAMPP kham_crm_api permitted.\n");
    exit(2);
}

$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$send = static function (array $event, ?string $signature) use ($kernel): array {
    $headers = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    if ($signature !== null) $headers['HTTP_MYFATOORAH_SIGNATURE'] = $signature;
    $request = Request::create(
        'http://localhost/api/v1/storefront/payments/myfatoorah/local-webhook',
        'POST', [], [], [], $headers, json_encode($event, JSON_THROW_ON_ERROR)
    );
    $response = $kernel->handle($request);
    try {
        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    } finally {
        $kernel->terminate($request, $response);
    }
};
$secret = 'synthetic-local-webhook-signing-secret';
$sign = static function (array $event) use ($secret): string {
    $data = $event['Data'];
    $fields = [
        'Invoice.Id', 'Invoice.Status', 'Transaction.Status',
        'Transaction.PaymentId', 'Invoice.ExternalIdentifier',
    ];
    $parts = [];
    foreach ($fields as $field) {
        $parts[] = $field . '=' . (string) data_get($data, $field, '');
    }
    return base64_encode(hash_hmac('sha256', implode(',', $parts), $secret, true));
};
$fakeProvider = static function (array $details): void {
    // Each fake must use a new factory to avoid retaining old matching callbacks.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake([
        'https://apitest.myfatoorah.com/v3/payments/*' => Http::response([
            'IsSuccess' => true, 'Data' => $details,
        ], 200),
    ]);
};

$market = Market::query()->where('code', 'AE')->first();
$warehouse = DB::table('warehouses')->where('code', 'EG-01')->first();
$stock = $warehouse ? DB::table('inventory_stocks')->where('warehouse_id', $warehouse->id)
    ->whereRaw('(on_hand - reserved - damaged) >= 2')->first() : null;
if (!$market || !$warehouse || !$stock) {
    fwrite(STDERR, "STOP: Need AE market and EG-01 variant with 2 sellable units.\n");
    exit(2);
}
$variant = DB::table('product_variants')->where('id', $stock->variant_id)->first();
if (!$variant) {
    fwrite(STDERR, "STOP: Missing stock variant.\n");
    exit(2);
}
$tables = ['orders','order_items','customers','payments',
    'inventory_movements','myfatoorah_webhook_receipts'];
$counts = static function () use ($tables): array {
    $out = [];
    foreach ($tables as $table) $out[$table] = DB::table($table)->count();
    return $out;
};
$before = $counts();
$stockBefore = DB::table('inventory_stocks')->where('id', $stock->id)->first();
$configBefore = [
    'commerce.myfatoorah_sandbox_enabled' => config('commerce.myfatoorah_sandbox_enabled'),
    'commerce.myfatoorah_sandbox_webhook_enabled' => config('commerce.myfatoorah_sandbox_webhook_enabled'),
    'commerce.myfatoorah_sandbox_token' => config('commerce.myfatoorah_sandbox_token'),
    'commerce.myfatoorah_webhook_secret' => config('commerce.myfatoorah_webhook_secret'),
];
$exit = 0;
DB::beginTransaction();
try {
    RateLimiter::for('storefront-test-order',
        fn (Request $request) => Limit::perMinute(100)->by($request->ip()));
    Http::preventStrayRequests();
    DB::table('product_market_prices')->updateOrInsert(
        ['variant_id' => $variant->id, 'market_id' => $market->id],
        ['currency_id' => $market->currency_id, 'price' => 115,
         'compare_at_price' => null, 'starts_at' => null, 'ends_at' => null,
         'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
    $customer = Customer::create([
        'first_name' => 'Webhook HTTP Regression',
        'email' => 'webhook-' . Str::random(16) . '@example.invalid',
        'phone' => '+971501234567', 'marketing_opt_in' => false,
    ]);
    $order = app(OrderService::class)->create([
        'market_id' => $market->id,
        'customer_id' => $customer->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_mode' => 'manual',
        'shipping_total' => 0,
        'tax_total' => 0,
    ]);
    $invoice = (string) random_int(100000000, 999999999);
    $paymentId = (string) random_int(100000000, 999999999);
    $payment = $order->payments()->create([
        'provider' => 'myfatoorah', 'method' => 'hosted',
        'transaction_reference' => $invoice,
        'status' => 'pending', 'amount' => $order->grand_total,
        'currency_code' => $order->currency_code,
        'payload' => ['sandbox' => true],
    ]);
    $event = [
        'Event' => [
            'Code' => 1, 'Name' => 'PAYMENT_STATUS_CHANGED',
            'Reference' => 'WH-'.Str::random(12),
        ],
        'Data' => [
            'Invoice' => [
                'Id' => $invoice, 'Status' => 'PAID',
                'ExternalIdentifier' => $order->order_number,
            ],
            'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => $paymentId],
        ],
    ];
    $provider = [
        'Invoice' => [
            'Id' => $invoice, 'Status' => 'PAID',
            'ExternalIdentifier' => $order->order_number,
        ],
        'Transaction' => ['PaymentId' => $paymentId, 'Status' => 'SUCCESS'],
        'Amount' => [
            'DisplayCurrency' => $order->currency_code,
            'ValueInDisplayCurrency' => (string) $order->grand_total,
        ],
    ];
    $signature = $sign($event);
    config([
        'commerce.myfatoorah_sandbox_enabled' => false,
        'commerce.myfatoorah_sandbox_webhook_enabled' => false,
        'commerce.myfatoorah_sandbox_token' => 'fake-test-token-no-real-gateway',
        'commerce.myfatoorah_webhook_secret' => $secret,
    ]);
    [$status] = $send($event, $signature);
    $assert($status === 404, 'Webhook endpoint disabled by default');

    config([
        'commerce.myfatoorah_sandbox_enabled' => true,
        'commerce.myfatoorah_sandbox_webhook_enabled' => true,
    ]);
    [$status] = $send($event, 'forged');
    $assert($status === 422, 'Forged signature rejected at HTTP boundary');

    [$status] = $send($event, null);
    $assert($status === 422, 'Missing signature rejected at HTTP boundary');

    $unknown = $event;
    $unknown['Data']['Invoice']['Id'] = '99999999999999999999';
    [$status] = $send($unknown, $sign($unknown));
    $assert($status === 422, 'Unknown signed invoice cannot select an order');

    $badProvider = $provider;
    $badProvider['Amount']['ValueInDisplayCurrency'] = '999.0000';
    $fakeProvider($badProvider);
    [$status] = $send($event, $signature);
    $assert($status === 422, 'Wrong authoritative amount rejected over HTTP');

    $pendingProvider = $provider;
    $pendingProvider['Transaction']['Status'] = 'INPROGRESS';
    $fakeProvider($pendingProvider);
    [$status, $body] = $send($event, $signature);
    $assert($status === 202 && ($body['code'] ?? '') === 'PAYMENT_NOT_CONFIRMED',
        'Pending provider enquiry does not settle order');
    $assert($order->fresh()->payment_status === PaymentStatus::Pending,
        'Pending enquiry preserves order payment state');

    $stockBeforePayment = DB::table('inventory_stocks')->where('id', $stock->id)->first();
    $fakeProvider($provider);
    [$status, $body] = $send($event, $signature);
    $assert($status === 200 && ($body['code'] ?? '') === 'SANDBOX_PAYMENT_VERIFIED'
        && ($body['data']['order_payment_status_changed'] ?? false) === true,
        'Verified callback settles matching stored payment');
    $assert($order->fresh()->payment_status === PaymentStatus::Paid
        && $payment->fresh()->status === PaymentStatus::Paid->value,
        'Order and payment both marked paid');
    $assert(DB::table('myfatoorah_webhook_receipts')
        ->where('provider_invoice_id', $invoice)->where('result', 'applied')->count() === 1,
        'Applied receipt stored once');

    [$status, $body] = $send($event, $signature);
    $assert($status === 200 && ($body['data']['duplicate'] ?? false) === true,
        'Signed webhook replay returns duplicate without settling again');
    $assert(DB::table('myfatoorah_webhook_receipts')
        ->where('provider_invoice_id', $invoice)->count() === 1
        && DB::table('payments')->where('order_id', $order->id)->count() === 1,
        'Replay does not create extra receipts or payments');

    $stockAfterPayment = DB::table('inventory_stocks')->where('id', $stock->id)->first();
    $assert((int) $stockAfterPayment->reserved === (int) $stockBeforePayment->reserved
        && (int) $stockAfterPayment->on_hand === (int) $stockBeforePayment->on_hand,
        'Payment callbacks do not change Egypt stock');


    // A valid provider-paid callback must not settle an order already cancelled.
    $cancelledOrder = app(OrderService::class)->create([
        'market_id' => $market->id, 'customer_id' => $customer->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_mode' => 'manual', 'shipping_total' => 0, 'tax_total' => 0,
    ]);
    $cancelInvoice = (string) random_int(100000000, 999999999);
    $cancelPaymentId = (string) random_int(100000000, 999999999);
    $cancelAttempt = $cancelledOrder->payments()->create([
        'provider' => 'myfatoorah', 'method' => 'hosted',
        'transaction_reference' => $cancelInvoice, 'status' => 'pending',
        'amount' => $cancelledOrder->grand_total,
        'currency_code' => $cancelledOrder->currency_code,
        'payload' => ['sandbox' => true],
    ]);
    app(\App\Services\OrderStatusService::class)->transition(
        $cancelledOrder, \App\Enums\OrderStatus::Cancelled
    );
    $cancelEvent = $event;
    $cancelEvent['Data']['Invoice']['Id'] = $cancelInvoice;
    $cancelEvent['Data']['Invoice']['ExternalIdentifier'] = $cancelledOrder->order_number;
    $cancelEvent['Data']['Transaction']['PaymentId'] = $cancelPaymentId;
    $cancelProvider = $provider;
    $cancelProvider['Invoice']['Id'] = $cancelInvoice;
    $cancelProvider['Invoice']['ExternalIdentifier'] = $cancelledOrder->order_number;
    $cancelProvider['Transaction']['PaymentId'] = $cancelPaymentId;
    $cancelProvider['Amount']['ValueInDisplayCurrency'] = (string) $cancelledOrder->grand_total;
    $fakeProvider($cancelProvider);
    $receiptCount = DB::table('myfatoorah_webhook_receipts')->count();
    [$status] = $send($cancelEvent, $sign($cancelEvent));
    $assert($status === 422
        && $cancelledOrder->fresh()->payment_status === PaymentStatus::Pending
        && $cancelAttempt->fresh()->status === 'pending',
        'Verified paid callback cannot settle cancelled order');
    $assert(DB::table('myfatoorah_webhook_receipts')->count() === $receiptCount,
        'Rejected cancelled-order callback rolls back receipt');
    echo "ALL LOCAL MYFATOORAH WEBHOOK HTTP TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
} finally {
    DB::rollBack();
    config($configBefore);
    RateLimiter::for('storefront-test-order',
        fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
}
$after = $counts();
$stockRestored = DB::table('inventory_stocks')->where('id', $stock->id)->first();
if ($before !== $after
    || (int) $stockRestored->reserved !== (int) $stockBefore->reserved
    || (int) $stockRestored->on_hand !== (int) $stockBefore->on_hand) {
    fwrite(STDERR, "FAIL: Rollback did not restore original DB counts and stock.\n");
    $exit = 1;
} else {
    echo "PASS: Original records and stock preserved after HTTP test rollback.\n";
}
exit($exit);
