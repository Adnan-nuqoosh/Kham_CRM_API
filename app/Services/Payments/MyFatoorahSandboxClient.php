<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MyFatoorah V3 hosted-payment sandbox transport.
 *
 * No live endpoint, public controller, webhook or order/payment mutation.
 * The caller must first approve tax, payable amount and gateway currency.
 */
final class MyFatoorahSandboxClient
{
    private const BASE_URL = 'https://apitest.myfatoorah.com';

    public function createHostedPayment(
        string $amount,
        string $redirectUrl,
        string $method = 'CARD'
    ): array {
        $this->assertEnabled();
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount) || (float) $amount <= 0) {
            throw new \InvalidArgumentException('A positive amount with at most 2 decimals is required.');
        }
        if (!in_array($method, ['CARD', 'APPLE_PAY', 'GOOGLE_PAY', 'KNET'], true)) {
            throw new \InvalidArgumentException('Unsupported hosted payment method.');
        }
        if (!$this->isSafeRedirect($redirectUrl)) {
            throw new \InvalidArgumentException('A valid HTTPS redirection URL is required.');
        }

        $response = $this->request()->post(self::BASE_URL . '/v3/payments', [
            'PaymentMethod' => $method,
            'OperationType' => 'PAY',
            'Order' => ['Amount' => (float) $amount],
            'IntegrationUrls' => ['Redirection' => $redirectUrl],
        ]);

        if (!$response->successful() || $response->json('IsSuccess') !== true) {
            throw new RuntimeException('MyFatoorah sandbox rejected payment creation.');
        }
        $url = $response->json('Data.PaymentURL');
        $invoiceId = $response->json('Data.InvoiceId');
        if (!is_string($url) || !$this->isMyFatoorahHostedUrl($url)
            || (!is_string($invoiceId) && !is_int($invoiceId))) {
            throw new RuntimeException('MyFatoorah sandbox returned invalid payment details.');
        }

        return [
            'invoice_id' => (string) $invoiceId,
            'payment_url' => $url,
            'payment_id' => $response->json('Data.PaymentId'),
            'payment_completed' => $response->json('Data.PaymentCompleted') === true,
        ];
    }

    public function getPaymentDetails(string $paymentId): array
    {
        $this->assertEnabled();
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $paymentId)) {
            throw new \InvalidArgumentException('Invalid payment ID.');
        }

        $response = $this->request()->get(
            self::BASE_URL . '/v3/payments/' . rawurlencode($paymentId)
        );
        if (!$response->successful() || $response->json('IsSuccess') !== true) {
            throw new RuntimeException('MyFatoorah sandbox payment verification failed.');
        }
        $invoice = $response->json('Data.Invoice');
        $transaction = $response->json('Data.Transaction');
        if (!is_array($invoice) || !is_array($transaction)
            || !is_string($transaction['PaymentId'] ?? null)
            || !hash_equals($paymentId, $transaction['PaymentId'])) {
            throw new RuntimeException('Payment ID mismatch or incomplete gateway response.');
        }

        $invoiceStatus = strtoupper((string) ($invoice['Status'] ?? ''));
        $transactionStatus = strtoupper((string) ($transaction['Status'] ?? ''));
        return [
            'payment_id' => $paymentId,
            'invoice_id' => (string) ($invoice['Id'] ?? ''),
            'invoice_status' => $invoiceStatus,
            'transaction_status' => $transactionStatus,
            // This is a gateway observation, NOT permission to update an order.
            // Currency/amount/order binding must be checked by the reconciliation layer.
            'gateway_reports_paid' => $invoiceStatus === 'PAID'
                && $transactionStatus === 'SUCCESS',
            'amount' => $response->json('Data.Amount'),
        ];
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $token = config('commerce.myfatoorah_sandbox_token');
        if (!is_string($token) || trim($token) === '') {
            throw new RuntimeException('MyFatoorah sandbox token is not configured.');
        }
        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(12)
            ->connectTimeout(5);
    }

    private function assertEnabled(): void
    {
        if (!app()->environment('local', 'testing')
            || config('commerce.myfatoorah_sandbox_enabled') !== true) {
            throw new RuntimeException('MyFatoorah sandbox client is disabled.');
        }
    }

    private function isSafeRedirect(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && is_string($parts['host'] ?? null)
            && !isset($parts['user'], $parts['pass'])
            && !filter_var($parts['host'], FILTER_VALIDATE_IP)
            && !str_contains($parts['host'], 'localhost')
            && !str_ends_with(strtolower($parts['host']), '.local');
    }

    private function isMyFatoorahHostedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        return $host === 'myfatoorah.com' || str_ends_with($host, '.myfatoorah.com');
    }
}
