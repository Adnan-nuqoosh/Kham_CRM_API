<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Region-specific MyFatoorah V3 live adapter.
 * No public route invokes it until production checkout and reconciliation
 * are implemented and tested. Do not expose token or provider errors.
 */
final class MyFatoorahLiveClient
{
    private const REGIONS = [
        'EG' => 'https://api-eg.myfatoorah.com/v3',
        'AE' => 'https://api-ae.myfatoorah.com/v3',
        'SA' => 'https://api-sa.myfatoorah.com/v3',
    ];

    private function credentials(string $marketCode): array
    {
        $region = strtoupper(trim($marketCode));
        if (!array_key_exists($region, self::REGIONS)) {
            throw ValidationException::withMessages(['market' => ['Unsupported payment market.']]);
        }
        if (!app()->environment('production') || !config('commerce.myfatoorah_live_enabled', false)) {
            throw new RuntimeException('Live payments are not enabled.');
        }
        $token = trim((string) config('commerce.myfatoorah_live_tokens.'.$region, ''));
        if ($token === '') {
            throw new RuntimeException('Payment provider is not configured for this market.');
        }
        return [self::REGIONS[$region], $token];
    }

    /**
     * Must be called only after idempotent order and payment reservation are
     * committed to DB. Never accept amount or reference directly from a browser.
     */
    public function createHostedPayment(
        string $marketCode,
        string $orderReference,
        string $amount,
        string $redirectUrl,
        string $idempotencyKey,
        ?string $method = null
    ): array {
        [$base, $token] = $this->credentials($marketCode);
        if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $orderReference)
            || !preg_match('/^(0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D', $amount)
            || (float) $amount <= 0
            || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotencyKey)
            || !filter_var($redirectUrl, FILTER_VALIDATE_URL)
            || parse_url($redirectUrl, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['payment' => ['Invalid server payment initiation parameters.']]);
        }
        if ($method !== null && !in_array($method, ['CARD', 'APPLE_PAY', 'GOOGLE_PAY', 'KNET'], true)) {
            throw ValidationException::withMessages(['payment_method' => ['Unsupported payment method.']]);
        }
        $payload = [
            'Order' => ['Amount' => (float) $amount, 'ExternalIdentifier' => $orderReference],
            'IntegrationUrls' => ['Redirection' => $redirectUrl],
        ];
        if ($method !== null) {
            $payload['PaymentMethod'] = $method;
        }
        $response = Http::acceptJson()->withToken($token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->connectTimeout(5)->timeout(15)->post($base.'/payments', $payload);

        if (!$response->successful() || $response->json('IsSuccess') !== true) {
            throw new RuntimeException('Live payment initiation failed. Reconcile before retry.');
        }
        $data = $response->json('Data');
        $url = is_array($data) ? ($data['PaymentURL'] ?? null) : null;
        $invoice = is_array($data) ? ($data['InvoiceId'] ?? null) : null;
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || (!is_string($invoice) && !is_int($invoice))
            || !preg_match('/^[0-9]{1,64}$/D', (string) $invoice)) {
            throw new RuntimeException('Payment provider returned unusable identifiers; reconcile manually.');
        }
        return ['invoice_id' => (string) $invoice, 'payment_url' => $url];
    }

    public function getPaymentDetails(string $marketCode, string $paymentId): array
    {
        [$base, $token] = $this->credentials($marketCode);
        if (!preg_match('/^[0-9]{1,64}$/D', $paymentId)) {
            throw ValidationException::withMessages(['payment_id' => ['Invalid payment identifier.']]);
        }
        $response = Http::acceptJson()->withToken($token)
            ->connectTimeout(5)->timeout(15)->get($base.'/payments/'.$paymentId);

        if (!$response->successful() || $response->json('IsSuccess') !== true
            || !is_array($response->json('Data'))) {
            throw new RuntimeException('Live payment verification failed; do not change order status.');
        }
        return $response->json('Data');
    }
}
