<?php
/**
 * Local-only HTTP integration regression for the MyFatoorah sandbox webhook.
 *
 * Run: php scripts/local_myfatoorah_http_webhook_regression.php
 * Never calls the real provider. Fixtures and settlement are rolled back.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Market;
use App\Services\OrderService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and local MySQL kham_crm_api.\n");
    exit(2);
}

$market = Market::where('code', 'AE')->first();
$warehouse = DB::table('warehouses')->where('code', 'EG-01')->first();
$stock = $warehouse ? DB::table('inventory_stocks')
    ->where('warehouse_id', $warehouse->id)
    ->whereRaw('(on_hand - reserved - damaged) >= 2')->first() : null;
$variant = $stock ? DB::table('product_variants')->where('id', $stock->variant_id)->first() : null;
if (!$market || !$warehouse || !$stock || !$variant) {
    fwrite(STDERR, "STOP: Requires AE market, EG-01 warehouse and a stocked variant.\n");
    exit(2);
}

$assert = static function (bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
};
$secret = 'local-http-regression-secret-not-for-production';
$original = [
    'sandbox' => config('commerce.myfatoorah_sandbox_enabled'),
    'webhook' => config('commerce.myfatoorah_sandbox_webhook_enabled'),
    'secret' => config('commerce.myfatoorah_webhook_secret'),
    'token' => config('commerce.myfatoorah_sandbox_token'),
];
$tables = ['orders', 'order_items', 'customers', 'payments', 'inventory_movements',
    'myfatoorah_webhook_receipts'];
$snapshot = static function () use ($tables): array {
    $counts = [];
    foreach ($tables as $table) $counts[$table] = DB::table($table)->count();
    return $counts;
};
$before = $snapshot();
$stockBefore = DB::table('inventory_stocks')->where('id', $stock->id)->first();

$sign = static function (array $event) use ($secret): string {
    $fields = ['Invoice.Id', 'Invoice.Status', 'Transaction.Status',
        'Transaction.PaymentId', 'Invoice.ExternalIdentifier'];
    $parts = [];
    foreach ($fields as $field) {
        $parts[] = $field . '=' . (string) data_get($event['Data'], $field, '');
    }
    return base64_encode(hash_hmac('sha256', implode(',', $parts), $secret, true));
};
$send = static function (array $event, string $signature) use ($app): array {
    $request = Request::create(
        '/api/v1/storefront/payments/myfatoorah/local-webhook',
        'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MYFATOORAH_SIGNATURE' => $signature,
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode($event, JSON_THROW_ON_ERROR)
    );
    $response = $app->make(HttpKernel::class)->handle($request);
    $result = [$response->getStatusCode(), json_decode($response->getContent(), true)];
    $app->make(HttpKernel::class)->terminate($request, $response);
    return $result;
};
$eventFor = static function (string $invoice, string $payment, string $orderNumber): array {
    return [
        'Event' => ['Code' => 1, 'Name' => 'PAYMENT_STATUS_CHANGED',
            'Reference' => 'evt_' . Str::random(20)],
        'Data' => [
            'Invoice' => ['Id' => $invoice, 'Status' => 'PAID',
                'ExternalIdentifier' => $orderNumber],
            'Transaction' => ['Status' => 'SUCCESS', 'PaymentId' => $payment],
        ],
    ];
};
$providerFor = static function (array $event, string $amount, string $currency,
    string $invoiceStatus = 'PAID', string $transactionStatus = 'SUCCESS'): array {
    return [
        'Invoice' => ['Id' => data_get($event, 'Data.Invoice.Id'),
            'Status' => $invoiceStatus,
            'ExternalIdentifier' => data_get($event, 'Data.Invoice.ExternalIdentifier')],
        'Transaction' => ['PaymentId' => data_get($event, 'Data.Transaction.PaymentId'),
            'Status' => $transactionStatus],
        'Amount' => ['DisplayCurrency' => $currency, 'ValueInDisplayCurrency' => $amount],
    ];
};
$fakeProvider = static function (array $details): void {
    Http::fake(['apitest.myfatoorah.com/v3/payments/*' => Http::response([
        'IsSuccess' => true, 'Data' => $details,
    ], 200)]);
    Http::preventStrayRequests();
};
$exit = 0;
DB::beginTransaction();
try {
    config([
        'commerce.myfatoorah_sandbox_enabled' => true,
        'commerce.myfatoorah_sandbox_webhook_enabled' => true,
        'commerce.myfatoorah_webhook_secret' => $secret,
        'commerce.myfatoorah_sandbox_token' => 'fake-local-only-token',
    ]);
    DB::table('product_market_prices')->updateOrInsert(
        ['variant_id' => $variant->id, 'market_id' => $market->id],
        ['currency_id' => $market->currency_id, 'price' => 115,
            'compare_at_price' => null, 'starts_at' => null, 'ends_at' => null,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
    $customer = Customer::create([
        'first_name' => 'Webhook HTTP Regression',
        'email' => 'http-' . Str::random(14) . '@example.invalid',
        'phone' => '+971501234567', 'marketing_opt_in' => false,
    ]);
    $order = app(OrderService::class)->create([
        'market_id' => $market->id, 'customer_id' => $customer->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_mode' => 'manual', 'shipping_total' => 0, 'tax_total' => 0,
    ]);
    $invoice = (string) random_int(100000000, 999999999);
    $paymentId = (string) random_int(100000000, 999999999);
    $payment = $order->payments()->create([
        'provider' => 'myfatoorah', 'method' => 'hosted',
        'transaction_reference' => $invoice, 'status' => 'pending',
        'amount' => $order->grand_total, 'currency_code' => $order->currency_code,
        'payload' => ['sandbox' => true],
    ]);
    $event = $eventFor($invoice, $paymentId, $order->order_number);
    $fakeProvider($providerFor($event, (string) $order->grand_total, $order->currency_code));

    config(['commerce.myfatoorah_sandbox_webhook_enabled' => false]);
    [$status] = $send($event, $sign($event));
    $assert($status === 404, 'Disabled webhook rejects HTTP request');
    config(['commerce.myfatoorah_sandbox_webhook_enabled' => true]);

    [$status] = $send($event, 'invalid-signature');
    $assert($status === 422, 'Invalid HMAC signature rejected');

    $unknown = $eventFor((string) random_int(1000000000, 1999999999),
        $paymentId, $order->order_number);
    [$status] = $send($unknown, $sign($unknown));
    $assert($status === 422, 'Unknown invoice rejected before provider lookup');

    $fakeProvider($providerFor($event, (string) $order->grand_total,
        $order->currency_code, 'PENDING', 'PENDING'));
    [$status, $body] = $send($event, $sign($event));
    $assert($status === 202 && data_get($body, 'code') === 'PAYMENT_NOT_CONFIRMED',
        'Provider pending response does not settle order');
    $assert($order->fresh()->payment_status === PaymentStatus::Pending
        && DB::table('myfatoorah_webhook_receipts')->where('provider_invoice_id', $invoice)->count() === 0,
        'Pending webhook creates no receipt or paid transition');

    $fakeProvider($providerFor($event, (string) $order->grand_total,
        $order->currency_code));
    [$status, $body] = $send($event, $sign($event));
    $assert($status === 200 && data_get($body, 'data.order_payment_status_changed') === true,
        'Verified paid webhook settles through HTTP');
    $assert($order->fresh()->payment_status === PaymentStatus::Paid
        && $payment->fresh()->status === PaymentStatus::Paid->value
        && DB::table('myfatoorah_webhook_receipts')->where('provider_invoice_id', $invoice)
            ->where('result', 'applied')->count() === 1,
        'Paid order, payment and receipt saved together');

    [$status, $body] = $send($event, $sign($event));
    $assert($status === 200 && data_get($body, 'data.duplicate') === true
        && data_get($body, 'data.order_payment_status_changed') === false,
        'Duplicate callback idempotent');
    $assert(DB::table('payments')->where('order_id', $order->id)->count() === 1
        && DB::table('myfatoorah_webhook_receipts')->where('provider_invoice_id', $invoice)->count() === 1,
        'Duplicate callback creates no additional payment or receipt');

    // A fresh order lets us force a settlement rejection after receipt insert.
    $second = app(OrderService::class)->create([
        'market_id' => $market->id, 'customer_id' => $customer->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_mode' => 'manual', 'shipping_total' => 0, 'tax_total' => 0,
    ]);
    $invoice2 = (string) random_int(2000000000, 2999999999);
    $paymentId2 = (string) random_int(2000000000, 2999999999);
    $second->payments()->create([
        'provider' => 'myfatoorah', 'method' => 'hosted',
        'transaction_reference' => $invoice2, 'status' => 'pending',
        'amount' => $second->grand_total, 'currency_code' => $second->currency_code,
        'payload' => ['sandbox' => true],
    ]);
    $event2 = $eventFor($invoice2, $paymentId2, $second->order_number);
    $fakeProvider($providerFor($event2, (string) $second->grand_total, $second->currency_code));
    $second->forceFill(['status' => \App\Enums\OrderStatus::Cancelled])->save();
    [$status] = $send($event2, $sign($event2));
    $assert($status === 422, 'Ineligible cancelled order rejected during settlement');
    $assert(DB::table('myfatoorah_webhook_receipts')->where('provider_invoice_id', $invoice2)->count() === 0
        && $second->fresh()->payment_status === PaymentStatus::Pending,
        'Receipt insertion rolled back when settlement fails');

    $assert((int) DB::table('inventory_stocks')->where('id', $stock->id)->value('reserved')
        === (int) $stockBefore->reserved + 2
        && (int) DB::table('inventory_stocks')->where('id', $stock->id)->value('on_hand')
        === (int) $stockBefore->on_hand,
        'Webhook processing does not modify fulfillment stock');
    echo "ALL MYFATOORAH HTTP WEBHOOK CHECKS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
    config([
        'commerce.myfatoorah_sandbox_enabled' => $original['sandbox'],
        'commerce.myfatoorah_sandbox_webhook_enabled' => $original['webhook'],
        'commerce.myfatoorah_webhook_secret' => $original['secret'],
        'commerce.myfatoorah_sandbox_token' => $original['token'],
    ]);
}
$after = $snapshot();
$restored = DB::table('inventory_stocks')->where('id', $stock->id)->first();
if ($after !== $before || (int) $restored->reserved !== (int) $stockBefore->reserved
    || (int) $restored->on_hand !== (int) $stockBefore->on_hand) {
    fwrite(STDERR, "FAIL: Fixture rollback did not preserve existing data or stock.\n");
    $exit = 1;
} else {
    echo "PASS: Database fixtures and Egypt stock restored after rollback.\n";
}
exit($exit);
