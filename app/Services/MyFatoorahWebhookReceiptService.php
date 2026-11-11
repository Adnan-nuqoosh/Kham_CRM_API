<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stores validated webhook receipts idempotently. Never changes an order,
 * payment, stock, or fulfillment status.
 */
final class MyFatoorahWebhookReceiptService
{
    public function record(array $signedEvent, array $reconciliation): array
    {
        if (($signedEvent['requires_provider_reconciliation'] ?? null) !== true
            || ($reconciliation['requires_atomic_database_transition'] ?? null) !== true
            || ($reconciliation['verified_paid'] ?? null) !== true) {
            throw ValidationException::withMessages([
                'payment' => ['Verified provider payment reconciliation is required.'],
            ]);
        }

        foreach (['invoice_id', 'payment_id', 'external_identifier'] as $field) {
            $a = $signedEvent[$field] ?? null;
            $b = $reconciliation[$field] ?? null;
            if (!is_string($a) || $a === '' || strlen($a) > 128
                || !is_string($b) || !hash_equals($a, $b)) {
                throw ValidationException::withMessages([
                    'payment' => ['Signed event and provider enquiry do not match.'],
                ]);
            }
        }

        $eventHash = hash('sha256', implode("\x00", [
            $signedEvent['invoice_id'],
            $signedEvent['payment_id'],
            $signedEvent['external_identifier'],
            (string) ($signedEvent['invoice_status'] ?? ''),
            (string) ($signedEvent['transaction_status'] ?? ''),
        ]));

        return DB::transaction(function () use ($signedEvent, $eventHash): array {
            // DB unique(event_hash) guarantees at-most-one receipt per identical
            // signed event, even across concurrent worker processes.
            $inserted = DB::table('myfatoorah_webhook_receipts')->insertOrIgnore([
                'provider_payment_id' => $signedEvent['payment_id'],
                'provider_invoice_id' => $signedEvent['invoice_id'],
                'external_identifier' => $signedEvent['external_identifier'],
                'event_hash' => $eventHash,
                'event_reference' => $signedEvent['event_reference'] ?? null,
                'result' => 'verified_unapplied',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $receipt = DB::table('myfatoorah_webhook_receipts')
                ->where('event_hash', $eventHash)->first();

            return [
                'inserted' => $inserted === 1,
                'duplicate' => $inserted !== 1,
                'receipt_id' => $receipt?->id,
                'result' => 'verified_unapplied',
                'order_payment_status_changed' => false,
            ];
        });
    }
}
