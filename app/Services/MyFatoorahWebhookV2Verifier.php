<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Verifies MyFatoorah Webhook V2 PAYMENT_STATUS_CHANGED signatures.
 * This class performs NO order/payment mutations and exposes NO public route.
 *
 * Signed property order is prescribed by the official MyFatoorah V2
 * Payment Status Data Model documentation.
 */
final class MyFatoorahWebhookV2Verifier
{
    private const FIELDS = [
        'Invoice.Id',
        'Invoice.Status',
        'Transaction.Status',
        'Transaction.PaymentId',
        'Invoice.ExternalIdentifier',
    ];

    public function verify(array $event, ?string $header, string $secret): array
    {
        if (trim($secret) === '' || !is_string($header) || trim($header) === '') {
            throw ValidationException::withMessages([
                'signature' => ['Webhook signing key and signature are required.'],
            ]);
        }

        if (data_get($event, 'Event.Code') !== 1
            || data_get($event, 'Event.Name') !== 'PAYMENT_STATUS_CHANGED') {
            throw ValidationException::withMessages([
                'event' => ['Unsupported MyFatoorah webhook event.'],
            ]);
        }

        $data = $event['Data'] ?? null;
        if (!is_array($data)) {
            throw ValidationException::withMessages(['event' => ['Missing event data.']]);
        }

        $parts = [];
        foreach (self::FIELDS as $field) {
            $value = data_get($data, $field);
            if ($value !== null && !is_string($value) && !is_int($value)) {
                throw ValidationException::withMessages(['event' => ['Invalid signed field type.']]);
            }
            $parts[] = $field . '=' . ($value ?? '');
        }

        $message = implode(',', $parts);
        $expected = base64_encode(hash_hmac('sha256', $message, $secret, true));
        if (!hash_equals($expected, trim($header))) {
            throw ValidationException::withMessages(['signature' => ['Invalid MyFatoorah webhook signature.']]);
        }

        $invoiceId = (string) data_get($data, 'Invoice.Id', '');
        $paymentId = (string) data_get($data, 'Transaction.PaymentId', '');
        $externalId = (string) data_get($data, 'Invoice.ExternalIdentifier', '');
        $eventReference = (string) data_get($event, 'Event.Reference', '');

        if ($invoiceId === '' || $paymentId === '' || $externalId === ''
            || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventReference)) {
            throw ValidationException::withMessages([
                'event' => ['Signed invoice, payment and external order identifiers are required.'],
            ]);
        }

        return [
            // Reference is not covered by the V2 signature: never use it as
            // the only replay key; also deduplicate by provider payment ID.
            'event_reference' => $eventReference,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'external_identifier' => $externalId,
            'invoice_status' => (string) data_get($data, 'Invoice.Status'),
            'transaction_status' => (string) data_get($data, 'Transaction.Status'),
            // Verified signature is NOT sufficient to mark an order paid.
            // Reconcile with a stored payment attempt and verify amount/currency
            // using GET /v3/payments/{paymentId} before any DB mutation.
            'requires_provider_reconciliation' => true,
        ];
    }
}
