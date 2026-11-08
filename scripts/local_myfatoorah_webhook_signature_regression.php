<?php
/**
 * Official V2 PAYMENT_STATUS_CHANGED signature field order regression.
 * No MyFatoorah credentials, HTTP calls, or database access.
 *
 * Docs: https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model
 *       https://docs.myfatoorah.com/docs/webhook-signature
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MyFatoorahWebhookV2Verifier;
use Illuminate\Validation\ValidationException;

$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$rejects = static function (callable $fn, string $label) use ($assert): void {
    $error = null;
    try { $fn(); } catch (Throwable $e) { $error = $e; }
    $assert($error instanceof ValidationException, $label);
};

$secret = 'local-test-webhook-secret-not-real';
$event = [
    'Event' => [
        'Code' => 1,
        'Name' => 'PAYMENT_STATUS_CHANGED',
        'Reference' => 'WH-1137282',
    ],
    'Data' => [
        'Invoice' => [
            'Id' => '6409988',
            'Status' => 'PAID',
            'ExternalIdentifier' => 'asdqwd-f13sdf-fasjkz',
        ],
        'Transaction' => [
            'Status' => 'SUCCESS',
            'PaymentId' => '07076409988323998875',
        ],
    ],
];
$officialMessage = 'Invoice.Id=6409988,Invoice.Status=PAID,Transaction.Status=SUCCESS,Transaction.PaymentId=07076409988323998875,Invoice.ExternalIdentifier=asdqwd-f13sdf-fasjkz';
$signature = base64_encode(hash_hmac('sha256', $officialMessage, $secret, true));
$verifier = new MyFatoorahWebhookV2Verifier();

try {
    $verified = $verifier->verify($event, $signature, $secret);
    $assert($verified['invoice_id'] === '6409988'
        && $verified['payment_id'] === '07076409988323998875'
        && $verified['external_identifier'] === 'asdqwd-f13sdf-fasjkz'
        && $verified['event_reference'] === 'WH-1137282'
        && $verified['requires_provider_reconciliation'] === true,
        'Official ordered V2 signature message accepted; no paid transition');

    $changed = $event;
    $changed['Data']['Invoice']['Status'] = 'PENDING';
    $rejects(fn () => $verifier->verify($changed, $signature, $secret),
        'Tampered invoice status rejected');

    $changed = $event;
    $changed['Data']['Transaction']['PaymentId'] = '999';
    $rejects(fn () => $verifier->verify($changed, $signature, $secret),
        'Tampered payment ID rejected');

    $changed = $event;
    $changed['Data']['Invoice']['ExternalIdentifier'] = 'OTHER';
    $rejects(fn () => $verifier->verify($changed, $signature, $secret),
        'Tampered order reference rejected');

    $rejects(fn () => $verifier->verify($event, $signature, 'wrong-key'),
        'Wrong signing secret rejected');
    $rejects(fn () => $verifier->verify($event, null, $secret),
        'Missing signature header rejected');
    $rejects(fn () => $verifier->verify($event, $signature, ''),
        'Missing signing secret rejected');

    $changed = $event;
    $changed['Event']['Code'] = 2;
    $rejects(fn () => $verifier->verify($changed, $signature, $secret),
        'Unsupported refund event rejected');

    $changed = $event;
    $changed['Event']['Reference'] = '../invalid';
    $rejects(fn () => $verifier->verify($changed, $signature, $secret),
        'Invalid unsigned event reference rejected');

    $changed = $event;
    $changed['Data']['Invoice']['ExternalIdentifier'] = null;
    $nullMessage = 'Invoice.Id=6409988,Invoice.Status=PAID,Transaction.Status=SUCCESS,Transaction.PaymentId=07076409988323998875,Invoice.ExternalIdentifier=';
    $nullSignature = base64_encode(hash_hmac('sha256', $nullMessage, $secret, true));
    $rejects(fn () => $verifier->verify($changed, $nullSignature, $secret),
        'Correctly signed event with missing order reference rejected');

    echo "ALL MYFATOORAH V2 SIGNATURE TESTS PASSED; no network or database access.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
