<?php
/**
 * Local-only mocked hosted payment initiation regression.
 * Run: php scripts/local_myfatoorah_hosted_checkout_regression.php
 * No real gateway requests; all database writes are rolled back.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Api\V1\LocalMyFatoorahHostedCheckoutController;
use App\Http\Controllers\Api\V1\LocalMyFatoorahReservationStatusController;
use App\Http\Controllers\Api\V1\LocalMyFatoorahProviderEnquiryController;
use App\Models\Customer;
use App\Models\Market;
use App\Models\Order;
use App\Services\MyFatoorahV3Client;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and local MySQL kham_crm_api.\n");
    exit(2);
}
if (!Schema::hasTable('myfatoorah_checkout_reservations')) {
    fwrite(STDERR, "STOP: Run the additive local reservation migration before this regression.\n");
    exit(2);
}
$market = Market::where('code', 'AE')->first();
$warehouse = DB::table('warehouses')->where('code', 'EG-01')->first();
$stock = $warehouse ? DB::table('inventory_stocks')
    ->where('warehouse_id', $warehouse->id)
    ->whereRaw('(on_hand - reserved - damaged) >= 1')->first() : null;
$variant = $stock ? DB::table('product_variants')->where('id', $stock->variant_id)->first() : null;
if (!$market || !$warehouse || !$stock || !$variant) {
    fwrite(STDERR, "STOP: Requires AE market, EG-01 warehouse and stocked variant.\n");
    exit(2);
}
$assert = static function (bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
};
$tables = ['orders', 'order_items', 'customers', 'payments', 'inventory_movements', 'myfatoorah_checkout_reservations'];
$counts = static function () use ($tables): array {
    $result = [];
    foreach ($tables as $table) $result[$table] = DB::table($table)->count();
    return $result;
};
$before = $counts();
$stockBefore = DB::table('inventory_stocks')->where('id', $stock->id)->first();
$original = [
    'sandbox' => config('commerce.myfatoorah_sandbox_enabled'),
    'hosted' => config('commerce.myfatoorah_sandbox_hosted_checkout_enabled'),
    'token' => config('commerce.myfatoorah_sandbox_token'),
    'redirect' => config('commerce.myfatoorah_sandbox_redirect_url'),
];
$fake = static function (array $data, int $status = 200, bool $success = true): void {
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['apitest.myfatoorah.com/v3/payments' =>
        Http::response(['IsSuccess' => $success, 'Data' => $data], $status)]);
    Http::preventStrayRequests();
};
$invoke = static function (Order $order, array $body = []) use ($app): array {
    $body += ['idempotency_key' => 'sandbox_' . Str::random(24)];
    $request = Request::create('/api/v1/orders/' . $order->id
        . '/payments/myfatoorah/sandbox-hosted', 'POST', $body, [], [],
        ['HTTP_ACCEPT' => 'application/json']);
    // Laravel 12 Request does not expose setContainer/setRedirector.
    // Validation resolves through the framework's request container binding.
    $app->instance('request', $request);
    $response = $app->make(LocalMyFatoorahHostedCheckoutController::class)(
        $request, $order, $app->make(MyFatoorahV3Client::class));
    return [$response->getStatusCode(), $response->getData(true)];
};
$exit = 0;
DB::beginTransaction();
try {
    config([
        'commerce.myfatoorah_sandbox_enabled' => true,
        'commerce.myfatoorah_sandbox_hosted_checkout_enabled' => true,
        'commerce.myfatoorah_sandbox_token' => 'mock-token-not-real',
        'commerce.myfatoorah_sandbox_redirect_url' => 'https://example.invalid/sandbox/return',
    ]);
    DB::table('product_market_prices')->updateOrInsert(
        ['variant_id' => $variant->id, 'market_id' => $market->id],
        ['currency_id' => $market->currency_id, 'price' => 115,
            'compare_at_price' => null, 'starts_at' => null, 'ends_at' => null,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
    $customer = Customer::create([
        'first_name' => 'Hosted Regression',
        'email' => 'hosted-' . Str::random(14) . '@example.invalid',
        'phone' => '+971501234567', 'marketing_opt_in' => false,
    ]);
    $newOrder = static fn () => app(OrderService::class)->create([
        'market_id' => $market->id, 'customer_id' => $customer->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_mode' => 'manual', 'shipping_total' => 0, 'tax_total' => 0,
    ]);
    $order = $newOrder();
    $url = 'https://example.invalid/sandbox/pay/' . Str::random(20);
    $invoice = (string) random_int(100000000, 999999999);
    $fake(['InvoiceId' => $invoice, 'PaymentURL' => $url]);
    [$status, $body] = $invoke($order);
    $assert(Http::recorded(static function ($request) use ($order) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v3/payments')
            && (string) data_get($request->data(), 'Order.ExternalIdentifier') === (string) $order->order_number
            && (float) data_get($request->data(), 'Order.Amount') === (float) $order->grand_total;
    }), 'Hosted request sends trusted order reference and amount to provider');
    $assert($status === 201 && data_get($body, 'code') === 'SANDBOX_HOSTED_PAYMENT_CREATED',
        'Hosted payment initiated with mocked provider');
    $reservation = DB::table('myfatoorah_checkout_reservations')->where('order_id', $order->id)->first();
    $assert($reservation && $reservation->status === 'initiated' && $reservation->invoice_id === $invoice,
        'Durable reservation resolved after provider initiation');
    $payment = $order->payments()->sole();
    $assert($payment->transaction_reference === $invoice
        && $payment->status === PaymentStatus::Pending->value
        && $payment->amount === $order->grand_total
        && $payment->currency_code === $order->currency_code,
        'Provider invoice and server-side order amount stored pending');
    $assert(data_get($body, 'data.payment_url') === $url
        && $order->fresh()->payment_status === PaymentStatus::Pending,
        'Payment URL returned without marking order paid');

    // Inspect the stored reservation through the read-only controller.
    // It must never contact the provider or mutate payment state.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    $inspection = $app->make(LocalMyFatoorahReservationStatusController::class)($order);
    $inspectionData = $inspection->getData(true);
    $assert($inspection->getStatusCode() === 200
        && data_get($inspectionData, 'code') === 'SANDBOX_HOSTED_RESERVATION_STATUS'
        && data_get($inspectionData, 'data.initiation_status') === 'initiated'
        && data_get($inspectionData, 'data.invoice_id') === $invoice
        && data_get($inspectionData, 'data.safe_to_retry') === false
        && $order->payments()->count() === 1,
        'Read-only reservation inspection exposes stored attempt without provider call');

    // Provider enquiry is read-only, even when the gateway reports PAID.
    $enquire = static function (Order $target, string $paymentId) use ($app): array {
        $request = Request::create('/api/v1/orders/' . $target->id
            . '/payments/myfatoorah/sandbox-hosted/provider-enquiry',
            'POST', ['payment_id' => $paymentId], [], [],
            ['HTTP_ACCEPT' => 'application/json']);
        $app->instance('request', $request);
        $response = $app->make(LocalMyFatoorahProviderEnquiryController::class)(
            $request, $target, $app->make(MyFatoorahV3Client::class));
        return [$response->getStatusCode(), $response->getData(true)];
    };
    $providerPaymentId = (string) random_int(100000000, 999999999);
    $providerDetails = [
        'Invoice' => ['Id' => $invoice, 'ExternalIdentifier' => $order->order_number,
            'Status' => 'PAID'],
        'Transaction' => ['PaymentId' => $providerPaymentId, 'Status' => 'SUCCESS'],
        'Amount' => ['DisplayCurrency' => $order->currency_code,
            'ValueInDisplayCurrency' => (string) $order->grand_total],
    ];
    $fakeEnquiry = static function (array $details): void {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['apitest.myfatoorah.com/v3/payments/*' =>
            Http::response(['IsSuccess' => true, 'Data' => $details], 200)]);
        Http::preventStrayRequests();
    };
    $fakeEnquiry($providerDetails);
    [$enquiryStatus, $enquiryBody] = $enquire($order, $providerPaymentId);
    $assert($enquiryStatus === 200
        && data_get($enquiryBody, 'code') === 'SANDBOX_PROVIDER_ENQUIRY_MATCHED'
        && data_get($enquiryBody, 'data.provider_reports_paid') === true
        && data_get($enquiryBody, 'data.payment_settled') === false
        && $order->fresh()->payment_status === PaymentStatus::Pending
        && $order->payments()->sole()->status === PaymentStatus::Pending->value,
        'Provider paid enquiry matches invoice without settling order or payment');

    $wrongDetails = $providerDetails;
    $wrongDetails['Invoice']['ExternalIdentifier'] = 'wrong-order-reference';
    $fakeEnquiry($wrongDetails);
    try {
        $enquire($order, $providerPaymentId);
        throw new RuntimeException('FAIL: Provider enquiry accepted wrong order reference');
    } catch (ValidationException $e) {
        $assert($order->fresh()->payment_status === PaymentStatus::Pending
            && $order->payments()->sole()->status === PaymentStatus::Pending->value,
            'Provider enquiry rejects mismatched order reference without mutation');
    }

    $wrongDetails = $providerDetails;
    $wrongDetails['Amount']['ValueInDisplayCurrency'] = '99999.0000';
    $fakeEnquiry($wrongDetails);
    try {
        $enquire($order, $providerPaymentId);
        throw new RuntimeException('FAIL: Provider enquiry accepted wrong amount');
    } catch (ValidationException $e) {
        $assert($order->fresh()->payment_status === PaymentStatus::Pending,
            'Provider enquiry rejects mismatched amount without settlement');
    }

    foreach ([
        'invoice mismatch' => ['Invoice', 'Id', '987654321012345'],
        'payment mismatch' => ['Transaction', 'PaymentId', '987654321012345'],
        'currency mismatch' => ['Amount', 'DisplayCurrency', 'ZZZ'],
    ] as $label => [$section, $field, $value]) {
        $wrongDetails = $providerDetails;
        $wrongDetails[$section][$field] = $value;
        $fakeEnquiry($wrongDetails);
        try {
            $enquire($order, $providerPaymentId);
            throw new RuntimeException('FAIL: Provider enquiry accepted ' . $label);
        } catch (ValidationException $e) {
            $assert($order->fresh()->payment_status === PaymentStatus::Pending
                && $order->payments()->sole()->status === PaymentStatus::Pending->value,
                'Provider enquiry rejects ' . $label . ' without settlement');
        }
    }

    $pendingDetails = $providerDetails;
    $pendingDetails['Invoice']['Status'] = 'PENDING';
    $pendingDetails['Transaction']['Status'] = 'PENDING';
    $fakeEnquiry($pendingDetails);
    [$pendingStatus, $pendingBody] = $enquire($order, $providerPaymentId);
    $assert($pendingStatus === 200
        && data_get($pendingBody, 'data.provider_reports_paid') === false
        && data_get($pendingBody, 'data.reconciliation_status') === 'manual_review_required'
        && $order->fresh()->payment_status === PaymentStatus::Pending,
        'Pending provider enquiry reports unpaid and requires manual review');

    $fakeEnquiry($providerDetails);
    $unlinkedOrder = $newOrder();
    try {
        $enquire($unlinkedOrder, $providerPaymentId);
        throw new RuntimeException('FAIL: Provider enquiry accepted missing reservation');
    } catch (ValidationException $e) {
        $assert($unlinkedOrder->payments()->count() === 0,
            'Provider enquiry refuses missing persisted invoice');
    }

    $sameKeyOrder = $newOrder();
    $sameKey = 'sandbox_' . Str::random(24);
    $sameInvoice = (string) random_int(100000000, 999999999);
    $fake(['InvoiceId' => $sameInvoice, 'PaymentURL' => $url]);
    [$firstStatus] = $invoke($sameKeyOrder, ['idempotency_key' => $sameKey]);
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    [$reusedStatus, $reusedBody] = $invoke($sameKeyOrder, ['idempotency_key' => $sameKey]);
    $assert($firstStatus === 201 && $reusedStatus === 200
        && data_get($reusedBody, 'code') === 'SANDBOX_HOSTED_PAYMENT_REUSED'
        && data_get($reusedBody, 'data.invoice_id') === $sameInvoice,
        'Same idempotency key returns stored invoice without provider call');
    try {
        $invoke($order);
        throw new RuntimeException('FAIL: Different-key duplicate was allowed');
    } catch (ValidationException $e) {
        $assert($order->payments()->count() === 1,
            'Different-key duplicate initiation blocked');
    }

    // A provider invoice must never be assigned to two different orders.
    $collision = $newOrder();
    $fake(['InvoiceId' => $invoice, 'PaymentURL' => $url]);
    try {
        $invoke($collision);
        throw new RuntimeException('FAIL: Duplicate provider invoice was accepted');
    } catch (ValidationException $e) {
        $assert($collision->payments()->count() === 0
            && DB::table('myfatoorah_checkout_reservations')->where('order_id', $collision->id)->value('status') === 'uncertain'
            && DB::table('myfatoorah_checkout_reservations')->where('order_id', $collision->id)->value('invoice_id') === null
            && $order->payments()->count() === 1,
            'Duplicate provider invoice leaves blocked uncertain reservation');
    }

    // Exercise the database index directly, bypassing the controller's guard.
    // This catches regressions where concurrent requests could attach one
    // provider invoice to two orders despite passing application validation.
    $indexCollision = $newOrder();
    try {
        $indexCollision->payments()->create([
            'provider' => 'myfatoorah',
            'method' => 'hosted',
            'transaction_reference' => $invoice,
            'status' => PaymentStatus::Pending->value,
            'amount' => $indexCollision->grand_total,
            'currency_code' => $indexCollision->currency_code,
            'payload' => ['sandbox' => true],
        ]);
        throw new RuntimeException('FAIL: Database accepted duplicate provider invoice');
    } catch (\Illuminate\Database\QueryException $e) {
        $assert($indexCollision->payments()->count() === 0
            && $order->payments()->count() === 1,
            'Database unique index rejects duplicate provider invoice');
    }

    // The same reference from a DIFFERENT provider remains legal.
    $otherProvider = $newOrder();
    $otherProvider->payments()->create([
        'provider' => 'other_provider',
        'method' => 'hosted',
        'transaction_reference' => $invoice,
        'status' => PaymentStatus::Pending->value,
        'amount' => $otherProvider->grand_total,
        'currency_code' => $otherProvider->currency_code,
        'payload' => ['sandbox' => true],
    ]);
    $assert($otherProvider->payments()->count() === 1,
        'Database unique index permits same reference for different provider');

    $failed = $newOrder();
    $failedKey = 'sandbox_' . Str::random(24);
    $fake([], 503, false);
    try {
        $invoke($failed, ['idempotency_key' => $failedKey]);
        throw new RuntimeException('FAIL: Provider failure was accepted');
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'FAIL:')) throw $e;
        $assert($failed->payments()->count() === 0
            && DB::table('myfatoorah_checkout_reservations')->where('order_id', $failed->id)->value('status') === 'uncertain',
            'Provider 503 retains uncertain reservation without payment');
    }

    $uncertainInspection = $app->make(LocalMyFatoorahReservationStatusController::class)($failed);
    $uncertainData = $uncertainInspection->getData(true);
    $assert($uncertainInspection->getStatusCode() === 200
        && data_get($uncertainData, 'data.initiation_status') === 'uncertain'
        && data_get($uncertainData, 'data.requires_manual_reconciliation') === true
        && data_get($uncertainData, 'data.safe_to_retry') === false
        && $failed->payments()->count() === 0,
        'Read-only uncertain inspection requires reconciliation and forbids retry');

    // An ambiguous provider failure must never trigger a second POST.
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    [$retryStatus, $retryBody] = $invoke($failed, ['idempotency_key' => $failedKey]);
    $assert($retryStatus === 202
        && data_get($retryBody, 'code') === 'SANDBOX_HOSTED_PAYMENT_UNRESOLVED'
        && data_get($retryBody, 'data.initiation_status') === 'uncertain'
        && $failed->payments()->count() === 0,
        'Uncertain same-key retry returns 202 without another provider call');
    try {
        $invoke($failed, ['idempotency_key' => 'sandbox_' . Str::random(24)]);
        throw new RuntimeException('FAIL: Different key bypassed uncertain reservation');
    } catch (ValidationException $e) {
        $assert(DB::table('myfatoorah_checkout_reservations')
            ->where('order_id', $failed->id)->count() === 1,
            'Uncertain reservation blocks a different idempotency key');
    }

    // Simulate a process crash after reservation was committed, before
    // a provider result was persisted. No provider POST is permitted on replay.
    $crashed = $newOrder();
    $crashedKey = 'sandbox_' . Str::random(24);
    DB::table('myfatoorah_checkout_reservations')->insert([
        'order_id' => $crashed->id,
        'idempotency_key_hash' => hash('sha256', $crashedKey),
        'status' => 'requesting',
        'amount' => $crashed->grand_total,
        'currency_code' => $crashed->currency_code,
        'provider_requested_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    [$crashStatus, $crashBody] = $invoke($crashed, ['idempotency_key' => $crashedKey]);
    $assert($crashStatus === 202
        && data_get($crashBody, 'data.initiation_status') === 'requesting'
        && $crashed->payments()->count() === 0,
        'Interrupted provider request fails closed without another provider call');

    $invalid = $newOrder();
    $fake(['InvoiceId' => '', 'PaymentURL' => $url]);
    try {
        $invoke($invalid);
        throw new RuntimeException('FAIL: Missing invoice was accepted');
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'FAIL:')) throw $e;
        $assert($invalid->payments()->count() === 0
            && DB::table('myfatoorah_checkout_reservations')->where('order_id', $invalid->id)->value('status') === 'uncertain',
            'Missing provider invoice retains uncertain reservation');
    }

    // Simulate an order cancellation occurring while provider HTTP is in flight.
    // Provider may have accepted the charge: retain an uncertain reservation.
    $midflightCancelled = $newOrder();
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['apitest.myfatoorah.com/v3/payments' => static function () use ($midflightCancelled, $url) {
        $midflightCancelled->forceFill(['status' => OrderStatus::Cancelled])->save();
        return Http::response(['IsSuccess' => true, 'Data' => [
            'InvoiceId' => (string) random_int(100000000, 999999999),
            'PaymentURL' => $url,
        ]], 200);
    }]);
    Http::preventStrayRequests();
    try {
        $invoke($midflightCancelled);
        throw new RuntimeException('FAIL: Midflight cancellation attached a payment');
    } catch (ValidationException $e) {
        $assert($midflightCancelled->payments()->count() === 0
            && DB::table('myfatoorah_checkout_reservations')
                ->where('order_id', $midflightCancelled->id)->value('status') === 'uncertain'
            && DB::table('myfatoorah_checkout_reservations')
                ->where('order_id', $midflightCancelled->id)->value('invoice_id') !== null,
            'Midflight cancelled order retains uncertain reservation without payment');
    }

    // A changed total after the provider call must not be attached to the
    // original invoice; an operator must reconcile the provider-side result.
    $midflightTotal = $newOrder();
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['apitest.myfatoorah.com/v3/payments' => static function () use ($midflightTotal, $url) {
        DB::table('orders')->where('id', $midflightTotal->id)
            ->update(['grand_total' => '9999.0000', 'updated_at' => now()]);
        return Http::response(['IsSuccess' => true, 'Data' => [
            'InvoiceId' => (string) random_int(100000000, 999999999),
            'PaymentURL' => $url,
        ]], 200);
    }]);
    Http::preventStrayRequests();
    try {
        $invoke($midflightTotal);
        throw new RuntimeException('FAIL: Midflight changed total attached a payment');
    } catch (ValidationException $e) {
        $assert($midflightTotal->payments()->count() === 0
            && DB::table('myfatoorah_checkout_reservations')
                ->where('order_id', $midflightTotal->id)->value('status') === 'uncertain',
            'Midflight amount change retains uncertain reservation without payment');
    }

    // A webhook or another verified flow may settle the order while the
    // hosted creation request is in flight. Never attach a pending attempt
    // after the order has already become paid.
    $midflightPaid = $newOrder();
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['apitest.myfatoorah.com/v3/payments' => static function () use ($midflightPaid, $url) {
        DB::table('orders')->where('id', $midflightPaid->id)
            ->update(['payment_status' => PaymentStatus::Paid->value, 'updated_at' => now()]);
        return Http::response(['IsSuccess' => true, 'Data' => [
            'InvoiceId' => (string) random_int(100000000, 999999999),
            'PaymentURL' => $url,
        ]], 200);
    }]);
    Http::preventStrayRequests();
    try {
        $invoke($midflightPaid);
        throw new RuntimeException('FAIL: Midflight paid order attached another payment');
    } catch (ValidationException $e) {
        $assert($midflightPaid->payments()->count() === 0
            && DB::table('myfatoorah_checkout_reservations')
                ->where('order_id', $midflightPaid->id)->value('status') === 'uncertain',
            'Midflight paid order blocks a second pending attempt');
    }

    $missingInspection = $app->make(LocalMyFatoorahReservationStatusController::class)($newOrder());
    $missingData = $missingInspection->getData(true);
    $assert($missingInspection->getStatusCode() === 200
        && data_get($missingData, 'data.reservation_exists') === false
        && data_get($missingData, 'data.safe_to_retry') === false,
        'Missing reservation inspection never claims provider retry is safe');

    $cancelled = $newOrder();
    $cancelled->forceFill(['status' => OrderStatus::Cancelled])->save();
    try {
        $invoke($cancelled);
        throw new RuntimeException('FAIL: Cancelled order was accepted');
    } catch (ValidationException $e) {
        $assert($cancelled->payments()->count() === 0,
            'Cancelled order cannot initiate hosted payment');
    }

    config(['commerce.myfatoorah_sandbox_hosted_checkout_enabled' => false]);
    try {
        $invoke($newOrder());
        throw new RuntimeException('FAIL: Disabled checkout was accepted');
    } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
        $assert(true, 'Hosted checkout disabled by default flag');
    }
    echo "ALL HOSTED CHECKOUT CHECKS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
    config([
        'commerce.myfatoorah_sandbox_enabled' => $original['sandbox'],
        'commerce.myfatoorah_sandbox_hosted_checkout_enabled' => $original['hosted'],
        'commerce.myfatoorah_sandbox_token' => $original['token'],
        'commerce.myfatoorah_sandbox_redirect_url' => $original['redirect'],
    ]);
}
$after = $counts();
$restored = DB::table('inventory_stocks')->where('id', $stock->id)->first();
if ($after !== $before || (int) $restored->reserved !== (int) $stockBefore->reserved
    || (int) $restored->on_hand !== (int) $stockBefore->on_hand) {
    fwrite(STDERR, "FAIL: Rollback did not restore database counts or stock.\n");
    $exit = 1;
} else {
    echo "PASS: Database fixtures and Egypt stock restored after rollback.\n";
}
exit($exit);
