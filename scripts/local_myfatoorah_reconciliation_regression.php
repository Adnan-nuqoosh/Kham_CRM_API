<?php
/**
 * Local-only, no-network, no-database payment reconciliation tests.
 * Provider details are illustrative fixtures, not live gateway evidence.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MyFatoorahPaymentReconciler;
use Illuminate\Validation\ValidationException;

if (!app()->environment('local', 'testing')) {
    fwrite(STDERR, "STOP: Only local/testing environments allowed.\n");
    exit(2);
}

$assert = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$rejects = static function (callable $callback, string $label) use ($assert): void {
    $error = null;
    try { $callback(); } catch (Throwable $e) { $error = $e; }
    $assert($error instanceof ValidationException, $label);
};

$event = [
    'invoice_id' => '6148108',
    'payment_id' => '12345',
    'external_identifier' => 'KH-2026-000001',
    'invoice_status' => 'PAID',
    'transaction_status' => 'SUCCESS',
];
$details = [
    'Invoice' => ['Id' => '6148108', 'ExternalIdentifier' => 'KH-2026-000001', 'Status' => 'PAID'],
    'Transaction' => ['PaymentId' => '12345', 'Status' => 'SUCCESS'],
    'Amount' => ['DisplayCurrency' => 'AED', 'ValueInDisplayCurrency' => '115.0000'],
];
$reconciler = new MyFatoorahPaymentReconciler();
$check = static fn (array $e, array $d, string $invoice = '6148108', string $external = 'KH-2026-000001', string $amount = '115.00', string $currency = 'AED') =>
    $reconciler->verify($e, $d, $invoice, $external, $amount, $currency);

try {
    $result = $check($event, $details);
    $assert($result['verified_paid'] === true && $result['amount'] === '115.0000'
        && $result['requires_atomic_database_transition'] === true,
        'Paid requires matching invoice, payment, order, amount, currency and both statuses');

    $pending = $details;
    $pending['Transaction']['Status'] = 'INPROGRESS';
    $assert($check($event, $pending)['verified_paid'] === false,
        'Provider pending transaction cannot mark paid');

    $pending = $event;
    $pending['invoice_status'] = 'PENDING';
    $assert($check($pending, $details)['verified_paid'] === false,
        'Webhook pending invoice cannot mark paid');

    $changed = $details;
    $changed['Invoice']['Id'] = 'other';
    $rejects(fn () => $check($event, $changed), 'Invoice mismatch rejected');

    $changed = $details;
    $changed['Transaction']['PaymentId'] = 'other';
    $rejects(fn () => $check($event, $changed), 'Payment ID mismatch rejected');

    $changed = $details;
    $changed['Invoice']['ExternalIdentifier'] = 'OTHER-ORDER';
    $rejects(fn () => $check($event, $changed), 'External order mismatch rejected');

    $changed = $details;
    $changed['Amount']['DisplayCurrency'] = 'SAR';
    $rejects(fn () => $check($event, $changed), 'Currency mismatch rejected');

    $changed = $details;
    $changed['Amount']['ValueInDisplayCurrency'] = '115.0001';
    $rejects(fn () => $check($event, $changed), 'Amount mismatch rejected without floating point rounding');

    $changed = $details;
    unset($changed['Amount']);
    $rejects(fn () => $check($event, $changed), 'Missing provider amount rejected');

    $rejects(fn () => $check($event, $details, amount: '115.00001'), 'Malformed amount rejected');

    echo "ALL READ-ONLY MYFATOORAH RECONCILIATION TESTS PASSED; no DB or network access.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
