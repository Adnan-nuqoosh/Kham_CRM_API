<?php

namespace App\Services;

use App\Models\Market;
use App\Models\MarketTaxRule;

class MarketTaxQuoteService
{
    /**
     * Read-only tax readiness information. Never returns a payable amount.
     * Approval is intentionally not exposed through customer input.
     */
    public function readiness(Market $market): array
    {
        $rule = MarketTaxRule::query()->where('market_id', $market->id)->first();
        if (!$rule) {
            return ['tax_status' => 'not_configured', 'is_final_payable_total' => false];
        }

        $approved = $rule->is_approved && $rule->approved_at !== null
            && $rule->approved_by !== null;
        return [
            'tax_status' => $approved ? 'approved_not_enabled' : 'pending_approval',
            'is_final_payable_total' => false,
        ];
    }
}
