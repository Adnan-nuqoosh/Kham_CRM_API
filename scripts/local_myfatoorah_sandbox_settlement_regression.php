<?php
/**
 * Local-only rollback test for sandbox atomic payment settlement.
 * No gateway calls. Requires seeded XAMPP kham_crm_api with EG-01 stock.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Market;
use App\Models\Order;
use App\Services\MyFatoorahSandboxSettlementService;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Only local XAMPP kham_crm_api is permitted.\n");
    exit(2);
}

$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$rejects = static function (callable $callback, string $label) use ($assert): void {
    $caught = false;
    try { $callback(); } catch (ValidationException $e) { $caught = true; }
    $assert($caught, $label);
};

$market = Market::query()->where('code', 'AE')->first();
$warehouse = DB::table('warehouses')->where('code', 'EG-01')->first();
if (!$market || !$warehouse) {
    fwrite(STDERR, "STOP: AE market or EG-01 warehouse is missing.\n");
    exit(2);
}
$stock = DB::table('inventory_stocks')->where('warehouse_id', $warehouse->id)
    ->whereRaw('(on_hand - reserved - damaged) >= 2')->first();
if (!$stock) {
    fwrite(STDERR, "STOP: Need at least 2 sellable units in Egypt stock.\n");
    exit(2);
}
$variant = DB::table('product_variants')->where('id', $stock->variant_id)->first();
if (!$variant) {
    fwrite(STDERR, "STOP: Inventory variant not found.\n");
    exit(2);
}

$tables = ['orders', 'order_items', 'customers', 'payments',
    'inventory_movements', 'myfatoorah_webhook_receipts'];
$counts = static function () use ($tables): array {
    $out = [];
    foreach ($tables as $table) $out[$table] = DB::table($table)->count();
    return $out;
};
$before = $counts();
$originalStock = DB::table('inventory_stocks')->where('id', $stock->id)->first();
$previousSandbox = config('commerce.myfatoorah_sandbox_enabled');
$exit = 0;
DB::beginTransaction();
try {
    DB::table('product_market_prices')->updateOrInsert(
        ['variant_id' => $variant->id, 'market_id' => $market->id],
        ['currency_id' => $market->currency_id, 'price' => 115,
         'compare_at_price' => null, 'starts_at' => null, 'ends_at' => null,
         'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );

    $customer = Customer::create([
        'first_name' => 'Settlement Regression',
        'email' => 'settlement-' . Str::random(14) . '@example.invalid',
        'phone' => '+971501234567',
        'marketing_opt_in' => false,
    ]);
    $order = app(OrderService::class)->create([
        'market_id' => $market->id,
        'customer_id' => $customer->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_mode' => 'manual',
        'shipping_total' => 0,
        'tax_total' => 0,
    ]);
    $invoiceId = (string) random_int(100000000, 999999999);
    $paymentId = (string) random_int(100000000, 999999999);
    $payment = $order->payments()->create([
        'provider' => 'myfatoorah',
        'method' => 'hosted',
        'transaction_reference' => $invoiceId,
        'status' => 'pending',
        'amount' => $order->grand_total,
        'currency_code' => $order->currency_code,
        'payload' => ['sandbox' => true],
    ]);

    $event = [
        'invoice_id' => $invoiceId,
        'payment_id' => $paymentId,
        'external_identifier' => $order->order_number,
        'invoice_status' => 'PAID',
        'transaction_status' => 'SUCCESS',
        'requires_provider_reconciliation' => true,
    ];
    $verified = [
        'invoice_id' => $invoiceId,
        'payment_id' => $paymentId,
        'external_identifier' => $order->order_number,
        'currency_code' => $order->currency_code,
        'amount' => (string) $order->grand_total,
        'provider_invoice_status' => 'PAID',
        'provider_transaction_status' => 'SUCCESS',
        'verified_paid' => true,
        'requires_atomic_database_transition' => true,
    ];

    $service = app(MyFatoorahSandboxSettlementService::class);
    config(['commerce.myfatoorah_sandbox_enabled' => false]);
    $rejects(fn () => $service->apply($order->id, $event, $verified),
        'Sandbox settlement disabled by default');

    config(['commerce.myfatoorah_sandbox_enabled' => true]);
    $bad = $verified;
    $bad['verified_paid'] = false;
    $rejects(fn () => $service->apply($order->id, $event, $bad),
        'Unverified provider payment rejected');

    $bad = $verified;
    $bad['invoice_id'] = 'wrong-invoice';
    $rejects(fn () => $service->apply($order->id, $event, $bad),
        'Webhook and enquiry invoice mismatch rejected');

    $bad = $verified;
    $bad['amount'] = '0.0000';
    $rejects(fn () => $service->apply($order->id, $event, $bad),
        'Mismatched order amount rejected');

    $bad = $verified;
    $bad['currency_code'] = 'SAR';
    $rejects(fn () => $service->apply($order->id, $event, $bad),
        'Mismatched order currency rejected');

    $bad = $verified;
    $bad['payment_id'] = 'wrong-payment';
    $rejects(fn () => $service->apply($order->id, $event, $bad),
        'Mismatched provider transaction rejected');

    $order->forceFill(['status' => \App\Enums\OrderStatus::Cancelled])->save();
    $rejects(fn () => $service->apply($order->id, $event, $verified),
        'Cancelled order cannot be settled');
    $order->forceFill(['status' => \App\Enums\OrderStatus::Pending])->save();

    $reservedBefore = (int) DB::table('inventory_stocks')->where('id', $stock->id)->value('reserved');
    $onHandBefore = (int) DB::table('inventory_stocks')->where('id', $stock->id)->value('on_hand');

    $first = $service->apply($order->id, $event, $verified);
    $assert($first['applied'] === true && $first['duplicate'] === false,
        'First verified settlement applied atomically');
    $assert($order->fresh()->payment_status === PaymentStatus::Paid
        && $payment->fresh()->status === PaymentStatus::Paid->value
        && $payment->fresh()->paid_at !== null,
        'Order and matching payment both marked paid');
    $assert((string) data_get($payment->fresh()->payload, 'verified_payment_id') === $paymentId,
        'Provider transaction ID saved on payment');

    $again = $service->apply($order->id, $event, $verified);
    $assert($again['applied'] === false && $again['duplicate'] === true,
        'Duplicate callback is idempotent');

    $changed = $event;
    $changed['payment_id'] = '987654321';
    $bad = $verified;
    $bad['payment_id'] = '987654321';
    $rejects(fn () => $service->apply($order->id, $changed, $bad),
        'Different transaction cannot resettle paid order');

    $assert((int) DB::table('inventory_stocks')->where('id', $stock->id)->value('reserved') === $reservedBefore
        && (int) DB::table('inventory_stocks')->where('id', $stock->id)->value('on_hand') === $onHandBefore,
        'Payment confirmation and replay do not alter Egypt stock');
    $assert(DB::table('payments')->where('order_id', $order->id)->count() === 1,
        'No duplicate payment rows created');

    echo "ALL MYFATOORAH SANDBOX SETTLEMENT TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
    config(['commerce.myfatoorah_sandbox_enabled' => $previousSandbox]);
}
$after = $counts();
$restored = DB::table('inventory_stocks')->where('id', $stock->id)->first();
if ($after !== $before
    || (int) $restored->reserved !== (int) $originalStock->reserved
    || (int) $restored->on_hand !== (int) $originalStock->on_hand) {
    fwrite(STDERR, "FAIL: Rollback did not preserve existing records or stock.\n");
    $exit = 1;
} else {
    echo "PASS: Original records and Egypt stock preserved after rollback.\n";
}
exit($exit);
