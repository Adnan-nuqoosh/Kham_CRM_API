<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sandbox-only atomic settlement of an already-created MyFatoorah payment attempt.
 * This is an internal service, NOT a public route. No real gateway credentials
 * or production settlement are supported by this implementation.
 */
final class MyFatoorahSandboxSettlementService
{
    public function apply(int $orderId, array $signedEvent, array $verified): array
    {
        if (!app()->environment('local', 'testing')
            || !config('commerce.myfatoorah_sandbox_enabled', false)) {
            throw ValidationException::withMessages([
                'payment' => ['Sandbox settlement is disabled.'],
            ]);
        }

        if (($signedEvent['requires_provider_reconciliation'] ?? null) !== true
            || ($verified['requires_atomic_database_transition'] ?? null) !== true
            || ($verified['verified_paid'] ?? null) !== true) {
            throw ValidationException::withMessages([
                'payment' => ['A verified paid provider enquiry is required.'],
            ]);
        }

        foreach (['invoice_id', 'payment_id', 'external_identifier'] as $field) {
            $a = $signedEvent[$field] ?? null;
            $b = $verified[$field] ?? null;
            if (!is_string($a) || $a === '' || !is_string($b) || !hash_equals($a, $b)) {
                throw ValidationException::withMessages([
                    'payment' => ['Webhook and provider enquiry identifiers mismatch.'],
                ]);
            }
        }
        if (($verified['provider_invoice_status'] ?? null) !== 'PAID'
            || ($verified['provider_transaction_status'] ?? null) !== 'SUCCESS'
            || ($signedEvent['invoice_status'] ?? null) !== 'PAID'
            || ($signedEvent['transaction_status'] ?? null) !== 'SUCCESS') {
            throw ValidationException::withMessages([
                'payment' => ['Provider payment is not confirmed paid.'],
            ]);
        }

        return DB::transaction(function () use ($orderId, $signedEvent, $verified): array {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (!hash_equals((string) $order->order_number, $verified['external_identifier'])) {
                throw ValidationException::withMessages([
                    'payment' => ['Order reference mismatch.'],
                ]);
            }
            if (!hash_equals((string) $order->currency_code, (string) ($verified['currency_code'] ?? ''))
                || !hash_equals((string) $order->grand_total, (string) ($verified['amount'] ?? ''))) {
                throw ValidationException::withMessages([
                    'payment' => ['Order currency or amount mismatch.'],
                ]);
            }

            // Invoice ID is stored when the server initiates a hosted payment.
            // Never settle a mock_fatoora attempt or an arbitrary payment row.
            $payments = $order->payments()->where('provider', 'myfatoorah')
                ->where('transaction_reference', $verified['invoice_id'])
                ->lockForUpdate()->get();
            if ($payments->count() !== 1) {
                throw ValidationException::withMessages([
                    'payment' => ['Exactly one matching MyFatoorah payment attempt is required.'],
                ]);
            }
            $payment = $payments->first();
            if (!hash_equals((string) $payment->currency_code, (string) $order->currency_code)
                || !hash_equals((string) $payment->amount, (string) $order->grand_total)) {
                throw ValidationException::withMessages([
                    'payment' => ['Stored payment amount or currency mismatch.'],
                ]);
            }

            $otherPaid = $order->payments()
                ->where('id', '<>', $payment->id)->where('status', PaymentStatus::Paid->value)
                ->exists();
            if ($otherPaid) {
                throw ValidationException::withMessages([
                    'payment' => ['Another payment is already paid for this order.'],
                ]);
            }

            if ($payment->status === PaymentStatus::Paid->value
                && $order->payment_status === PaymentStatus::Paid) {
                $existingId = (string) data_get($payment->payload, 'verified_payment_id', '');
                if (!hash_equals($existingId, $verified['payment_id'])) {
                    throw ValidationException::withMessages([
                        'payment' => ['Already settled using a different provider transaction.'],
                    ]);
                }
                return ['applied' => false, 'duplicate' => true,
                    'order_id' => $order->id, 'payment_id' => $payment->id];
            }

            if ($payment->status !== PaymentStatus::Pending->value
                || $order->payment_status !== PaymentStatus::Pending
                || in_array($order->status->value, ['cancelled', 'returned'], true)) {
                throw ValidationException::withMessages([
                    'payment' => ['Order or payment is not eligible for settlement.'],
                ]);
            }

            $payload = $payment->payload ?? [];
            $payload['verified_payment_id'] = $verified['payment_id'];
            $payload['verified_invoice_id'] = $verified['invoice_id'];
            $payment->forceFill([
                'status' => PaymentStatus::Paid->value,
                'paid_at' => now(),
                'payload' => $payload,
            ])->save();
            $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();

            // Deliberately do NOT commit or release inventory here.
            // Inventory is handled only by the order fulfillment lifecycle.
            return ['applied' => true, 'duplicate' => false,
                'order_id' => $order->id, 'payment_id' => $payment->id];
        }, 5);
    }
}
