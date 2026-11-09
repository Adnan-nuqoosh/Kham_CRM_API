<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RefundProviderEvidence extends Model
{
    public $timestamps = false;
    protected $table = 'refund_provider_evidence';
    protected $fillable = [];
    protected $casts = ['amount' => 'decimal:4', 'created_at' => 'datetime'];

    /**
     * Provider integration is intentionally not connected yet.
     * No HTTP endpoint or mass assignment may create a "verified" record.
     */
    public static function recordUnverified(
        OrderRefundRequest $refund,
        string $provider,
        string $reference,
        string $amount,
        string $currencyCode
    ): self {
        if ($refund->status !== 'approved_awaiting_provider'
            || trim($provider) === '' || trim($reference) === ''
            || (string) $refund->amount !== $amount
            || (string) $refund->currency_code !== $currencyCode) {
            throw ValidationException::withMessages([
                'provider_evidence' => ['Approval, provider reference, amount and currency must match.'],
            ]);
        }

        return self::query()->create([
            'refund_request_id' => $refund->id,
            'provider' => $provider,
            'provider_refund_reference' => $reference,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'verification_status' => 'unverified',
            'verification_source' => 'pending_provider_integration',
        ]);
    }
}
