<?php
/**
 * MyFatoorah V3 adapter contract tests. No DB writes and NO network calls.
 * Uses Laravel Http::fake() to simulate the official sandbox API.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MyFatoorahV3Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

if (!app()->environment('local', 'testing')) {
    fwrite(STDERR, "STOP: This regression is local/testing only.\n");
    exit(2);
}

$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$throws = static function (callable $fn, string $expected, string $label) use ($assert): void {
    $caught = null;
    try { $fn(); } catch (Throwable $e) { $caught = $e; }
    $assert($caught instanceof $expected, $label);
};

$previousEnabled = config('commerce.myfatoorah_sandbox_enabled');
$previousToken = config('commerce.myfatoorah_sandbox_token');
$exit = 0;
try {
    Http::preventStrayRequests();
    $client = app(MyFatoorahV3Client::class);
    config(['commerce.myfatoorah_sandbox_enabled' => false, 'commerce.myfatoorah_sandbox_token' => null]);
    $throws(fn () => $client->getPaymentDetails('12345'), RuntimeException::class,
        'Sandbox disabled by default');

    config(['commerce.myfatoorah_sandbox_enabled' => true]);
    $throws(fn () => $client->getPaymentDetails('12345'), RuntimeException::class,
        'Missing sandbox token fails closed');

    config(['commerce.myfatoorah_sandbox_token' => 'fake-token-for-mocked-tests-only']);
    $redirect = 'https://shop.example.test/payment/return';
    $key = 'mock-payment-order-000001';

    Http::fake([
        'apitest.myfatoorah.com/v3/payments' => Http::response([
            'IsSuccess' => true,
            'Data' => [
                'InvoiceId' => '6148108',
                'PaymentId' => null,
                'PaymentURL' => 'https://demo.myfatoorah.com/test/checkout',
                'PaymentCompleted' => false,
            ],
        ], 201),
        'apitest.myfatoorah.com/v3/payments/12345' => Http::response([
            'IsSuccess' => true,
            'Data' => [
                'Invoice' => ['Id' => '6148108', 'Status' => 'PENDING'],
                'Transaction' => ['PaymentId' => '12345', 'Status' => 'INPROGRESS'],
            ],
        ], 200),
    ]);

    $created = $client->createHostedPayment('115.0000', $redirect, $key);
    $assert($created['invoice_id'] === '6148108'
        && $created['payment_url'] === 'https://demo.myfatoorah.com/test/checkout'
        && $created['payment_completed'] === false,
        'V3 hosted payment response normalized without claiming paid');

    Http::assertSent(fn ($req) =>
        $req->method() === 'POST'
        && $req->url() === 'https://apitest.myfatoorah.com/v3/payments'
        && $req->hasHeader('Authorization', 'Bearer fake-token-for-mocked-tests-only')
        && $req->hasHeader('Idempotency-Key', $key)
        && (float) data_get($req->data(), 'Order.Amount') === 115.0
        && data_get($req->data(), 'IntegrationUrls.Redirection') === $redirect
        && !array_key_exists('SourceOfFund', $req->data())
    );
    echo "PASS: POST uses sandbox URL, bearer auth, idempotency and hosted-only payload\n";

    $details = $client->getPaymentDetails('12345');
    $assert(data_get($details, 'Invoice.Status') === 'PENDING',
        'GET payment details returns provider data without changing an order');
    Http::assertSent(fn ($req) =>
        $req->method() === 'GET'
        && $req->url() === 'https://apitest.myfatoorah.com/v3/payments/12345'
    );
    echo "PASS: GET uses correct V3 sandbox enquiry endpoint\n";

    $throws(fn () => $client->createHostedPayment('0', $redirect, $key),
        ValidationException::class, 'Zero payment amount rejected');
    $throws(fn () => $client->createHostedPayment('115', 'http://example.test/return', $key),
        ValidationException::class, 'Insecure redirect rejected');
    $throws(fn () => $client->createHostedPayment('115', $redirect, 'bad'),
        ValidationException::class, 'Invalid idempotency key rejected');
    $throws(fn () => $client->getPaymentDetails('../admin'),
        ValidationException::class, 'Unsafe payment ID rejected');

    // Laravel's Http::fake() registrations can retain earlier matching callbacks.
    // Swap in a fresh HTTP factory before each independent provider scenario.
    Http::swap(new \\Illuminate\\Http\\Client\\Factory());
    Http::preventStrayRequests();
    Http::fake([
        'apitest.myfatoorah.com/v3/payments' => Http::response([
            'IsSuccess' => false, 'Message' => 'Declined',
        ], 400),
    ]);
    $throws(fn () => $client->createHostedPayment('115', $redirect, $key),
        RuntimeException::class, 'Provider error is not treated as success');

    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake([
        'apitest.myfatoorah.com/v3/payments' => Http::response([
            'IsSuccess' => true, 'Data' => [
                'InvoiceId' => '123', 'PaymentURL' => 'http://insecure.example.test/pay',
            ],
        ], 201),
    ]);
    $throws(fn () => $client->createHostedPayment('115', $redirect, $key),
        RuntimeException::class, 'Insecure provider payment URL rejected');

    echo "ALL MYFATOORAH MOCK API TESTS PASSED; no real HTTP requests or DB changes.\n";
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    config([
        'commerce.myfatoorah_sandbox_enabled' => $previousEnabled,
        'commerce.myfatoorah_sandbox_token' => $previousToken,
    ]);
}
exit($exit);
