<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketTaxRule;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketTaxRuleController extends Controller
{
    public function index()
    {
        return ApiResponse::success(MarketTaxRule::with('market.currency')->orderBy('market_id')->get(),
            'Market tax policies fetched.', 'MARKET_TAX_RULE_LIST');
    }

    public function upsert(Request $request, Market $market)
    {
        $data = $request->validate([
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'calculation_method' => ['required', 'in:exclusive,inclusive'],
            'tax_shipping' => ['required', 'boolean'],
        ]);
        $rule = DB::transaction(function () use ($market, $data) {
            // Lock the parent market to serialize first creation and approval edits.
            Market::whereKey($market->id)->lockForUpdate()->firstOrFail();
            $rule = MarketTaxRule::where('market_id', $market->id)->lockForUpdate()->first();
            if (!$rule) {
                $rule = new MarketTaxRule(['market_id' => $market->id]);
            }
            $changed = !$rule->exists
                || (string) $rule->rate_percent !== number_format((float) $data['rate_percent'], 4, '.', '')
                || $rule->calculation_method !== $data['calculation_method']
                || (bool) $rule->tax_shipping !== (bool) $data['tax_shipping'];
            if ($changed) {
                $rule->fill($data);
                $rule->is_approved = false;
                $rule->approved_at = null;
                $rule->approved_by = null;
                $rule->save();
            }
            return $rule;
        });
        return ApiResponse::success($rule->fresh(), 'Tax policy saved; changed policies require approval.',
            'MARKET_TAX_RULE_SAVED');
    }

    public function approve(Request $request, Market $market)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $rule = DB::transaction(function () use ($market, $request) {
            Market::whereKey($market->id)->lockForUpdate()->firstOrFail();
            $rule = MarketTaxRule::where('market_id', $market->id)->lockForUpdate()->first();
            if (!$rule) {
                throw ValidationException::withMessages(['market_id' => ['Configure tax policy before approval.']]);
            }
            if (!$rule->is_approved) {
                $rule->forceFill([
                    'is_approved' => true,
                    'approved_at' => now(),
                    'approved_by' => $request->user()->id,
                ])->save();
            }
            return $rule;
        });
        return ApiResponse::success($rule->fresh(), 'Tax policy approved; final payable remains disabled.',
            'MARKET_TAX_RULE_APPROVED');
    }
}
