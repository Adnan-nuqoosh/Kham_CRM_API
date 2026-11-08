<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Pure, read-only verification of a signed Webhook V2 event against
 * GET /v3/payments/{paymentId}. No database writes or paid transitions.
 */
final class MyFatoorahPaymentReconciler
{
    public function verify(
        array $signedEvent,
        array $providerDetails,
        string $expectedInvoiceId,
        string $expectedExternalIdentifier,
        string $expectedAmount,
        string $expectedCurrency
    ): array {
        $webhookInvoice = (string) ($signedEvent['invoice_id'] ?? '');
        $webhookPayment = (string) ($signedEvent['payment_id'] ?? '');
        $webhookExternal = (string) ($signedEvent['external_identifier'] ?? '');

        $providerInvoice = (string) data_get($providerDetails, 'Invoice.Id', '');
        $providerPayment = (string) data_get($providerDetails, 'Transaction.PaymentId', '');
        $providerExternal = (string) data_get($providerDetails, 'Invoice.ExternalIdentifier', '');

        if ($expectedInvoiceId === '' || $expectedExternalIdentifier === ''
            || $webhookInvoice === '' || $webhookPayment === '' || $webhookExternal === ''
            || !hash_equals($expectedInvoiceId, $webhookInvoice)
            || !hash_equals($expectedInvoiceId, $providerInvoice)
            || !hash_equals($expectedExternalIdentifier, $webhookExternal)
            || !hash_equals($expectedExternalIdentifier, $providerExternal)
            || !hash_equals($webhookPayment, $providerPayment)) {
            throw ValidationException::withMessages([
                'payment' => ['Provider invoice, transaction or order reference mismatch.'],
            ]);
        }

        $currency = strtoupper(trim($expectedCurrency));
        $providerCurrency = strtoupper((string) data_get($providerDetails, 'Amount.DisplayCurrency', ''));
        if (!preg_match('/^[A-Z]{3}$/D', $currency)
            || !hash_equals($currency, $providerCurrency)) {
            throw ValidationException::withMessages([
                'currency' => ['Provider display currency does not match order currency.'],
            ]);
        }

        $providerAmount = data_get($providerDetails, 'Amount.ValueInDisplayCurrency');
        if (!is_string($providerAmount) && !is_int($providerAmount)) {
            throw ValidationException::withMessages(['amount' => ['Provider display amount is missing or invalid.']]);
        }
        if ($this->minorUnits($expectedAmount) !== $this->minorUnits((string) $providerAmount)) {
            throw ValidationException::withMessages([
                'amount' => ['Provider display amount does not match the stored order total.'],
            ]);
        }

        $webhookInvoiceStatus = (string) ($signedEvent['invoice_status'] ?? '');
        $webhookTransactionStatus = (string) ($signedEvent['transaction_status'] ?? '');
        $invoiceStatus = (string) data_get($providerDetails, 'Invoice.Status', '');
        $transactionStatus = (string) data_get($providerDetails, 'Transaction.Status', '');

        // A webhook SUCCESS alone is not enough: authoritative enquiry must
        // independently confirm both invoice PAID and transaction SUCCESS.
        $verifiedPaid = $webhookInvoiceStatus === 'PAID'
            && $webhookTransactionStatus === 'SUCCESS'
            && $invoiceStatus === 'PAID'
            && $transactionStatus === 'SUCCESS';

        return [
            'verified_paid' => $verifiedPaid,
            'invoice_id' => $providerInvoice,
            'payment_id' => $providerPayment,
            'external_identifier' => $providerExternal,
            'currency_code' => $currency,
            'amount' => $this->formatAmount($this->minorUnits($expectedAmount)),
            'provider_invoice_status' => $invoiceStatus,
            'provider_transaction_status' => $transactionStatus,
            'requires_atomic_database_transition' => true,
        ];
    }

    private function minorUnits(string $value): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,4}))?$/D', $value, $m)) {
            throw ValidationException::withMessages(['amount' => ['Invalid monetary amount.']]);
        }
        $fraction = str_pad($m[2] ?? '', 4, '0');
        $whole = (int) $m[1];
        if ($whole > intdiv(PHP_INT_MAX - (int) $fraction, 10000)) {
            throw ValidationException::withMessages(['amount' => ['Monetary amount exceeds supported range.']]);
        }
        return $whole * 10000 + (int) $fraction;
    }

    private function formatAmount(int $units): string
    {
        return intdiv($units, 10000) . '.' . str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }
}
