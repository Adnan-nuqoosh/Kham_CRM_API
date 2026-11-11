<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ShippingRule;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\ShippingQuoteService;

class ShippingRuleController extends Controller
{
    public function index(Request $request)
    {
        $query = ShippingRule::query()->with('market.currency')->orderBy('market_id')->orderBy('city_key');
        if ($request->filled('market_id')) {
            $query->where('market_id', $request->validate([
                'market_id' => ['integer', 'exists:markets,id'],
            ])['market_id']);
        }
        return ApiResponse::success($query->get(), 'Shipping rules fetched.', 'SHIPPING_RULE_LIST');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'market_id' => ['required', 'integer', 'exists:markets,id'],
            'city' => ['required', 'string', 'max:120'],
            'shipping_fee' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $city = trim($data['city']);
        $key = $city === '*' ? '*' : ShippingQuoteService::normalizeCity($city);
        if (ShippingRule::query()->where('market_id', $data['market_id'])->where('city_key', $key)->exists()) {
            throw ValidationException::withMessages(['city' => ['A shipping rule already exists for this market and city.']]);
        }
        $rule = ShippingRule::create([
            'market_id' => $data['market_id'],
            'city_key' => $key,
            'shipping_fee' => $data['shipping_fee'],
            'free_shipping_threshold' => $data['free_shipping_threshold'] ?? null,
            'is_active' => $data['is_active'] ?? false,
        ]);
        return ApiResponse::success($rule->load('market.currency'), 'Shipping rule created.', 'SHIPPING_RULE_CREATED', 201);
    }

    public function update(Request $request, ShippingRule $shippingRule)
    {
        // Market and city are immutable to avoid accidental cross-market rule reassignment.
        $data = $request->validate([
            'shipping_fee' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'free_shipping_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $shippingRule->update($data);
        return ApiResponse::success($shippingRule->fresh()->load('market.currency'), 'Shipping rule updated.', 'SHIPPING_RULE_UPDATED');
    }
}
