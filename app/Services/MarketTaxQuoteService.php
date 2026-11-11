<?php

namespace App\Services;

use App\Models\Market;
use App\Models\MarketTaxRule;
use Illuminate\Validation\ValidationException;

class MarketTaxQuoteService
{
    /**
     * Tax is never final payable until a separate checkout release gate exists.
     * Unapproved policies never produce a tax amount.
     */
    public function readiness(Market $market): array
    {
        $rule = MarketTaxRule::query()->where('market_id', $market->id)->first();
        if (!$rule) {
            return ['tax_status' => 'not_configured', 'is_final_payable_total' => false];
        }
        return [
            'tax_status' => $this->isApproved($rule) ? 'approved_not_enabled' : 'pending_approval',
            'is_final_payable_total' => false,
        ];
    }

    public function preview(Market $market, string $netSubtotal, string $shippingTotal): array
    {
        $rule = MarketTaxRule::query()->where('market_id', $market->id)->first();
        if (!$rule || !$this->isApproved($rule)) {
            return $this->readiness($market) + ['tax_total' => null];
        }

        $rate = (float) $rule->rate_percent;
        if ($rate < 0 || $rate > 100 || !in_array($rule->calculation_method, ['exclusive', 'inclusive'], true)) {
            throw ValidationException::withMessages(['tax' => ['Approved market tax configuration is invalid.']]);
        }
        $base = round((float) $netSubtotal + ($rule->tax_shipping ? (float) $shippingTotal : 0), 4);
        if ($base < 0) {
            throw ValidationException::withMessages(['tax' => ['Taxable amount cannot be negative.']]);
        }
        $amount = $rule->calculation_method === 'inclusive'
            ? $base * $rate / (100 + $rate)
            : $base * $rate / 100;
        return [
            'tax_status' => 'approved_not_enabled',
            'is_final_payable_total' => false,
            'tax_total' => number_format(round($amount, 4), 4, '.', ''),
            'tax_calculation_method' => $rule->calculation_method,
            'tax_shipping' => $rule->tax_shipping,
        ];
    }

    private function isApproved(MarketTaxRule $rule): bool
    {
        return $rule->is_approved && $rule->approved_at !== null && $rule->approved_by !== null;
    }
}
