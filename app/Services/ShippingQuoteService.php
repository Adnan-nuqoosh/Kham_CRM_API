<?php

namespace App\Services;

use App\Models\Market;
use App\Models\ShippingRule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShippingQuoteService
{
    public static function normalizeCity(string $city): string
    {
        $normalized = Str::lower(trim(preg_replace('/\s+/u', ' ', $city)));
        if ($normalized === '' || $normalized === '*') {
            throw ValidationException::withMessages([
                'shipping_address.city' => ['A valid destination city is required.'],
            ]);
        }
        return $normalized;
    }

    public function quote(Market $market, string $countryCode, string $city, float $netSubtotal): array
    {
        $market->loadMissing('currency');
        if (!$market->is_active || !$market->currency || !$market->currency->is_active) {
            throw ValidationException::withMessages(['market_id' => ['Selected sales market is unavailable.']]);
        }
        if (strtoupper(trim($countryCode)) !== strtoupper($market->country_code)) {
            throw ValidationException::withMessages([
                'shipping_address.country_code' => ['Shipping destination must match the selected sales market.'],
            ]);
        }
        $cityKey = self::normalizeCity($city);
        $rule = ShippingRule::query()
            ->where('market_id', $market->id)
            ->where('is_active', true)
            ->whereIn('city_key', [$cityKey, '*'])
            ->orderByRaw("CASE WHEN city_key = ? THEN 0 ELSE 1 END", [$cityKey])
            ->first();

        if (!$rule) {
            throw ValidationException::withMessages([
                'shipping_address.city' => ['Shipping is not yet configured for this destination.'],
            ]);
        }

        $threshold = $rule->free_shipping_threshold === null
            ? null : (float) $rule->free_shipping_threshold;
        $isFree = $threshold !== null && $netSubtotal >= $threshold;
        $shipping = $isFree ? 0.0 : (float) $rule->shipping_fee;

        return [
            'market_id' => $market->id,
            'currency_code' => $market->currency->code,
            'country_code' => strtoupper($market->country_code),
            'city' => $city,
            'rule_id' => $rule->id,
            'shipping_total' => number_format($shipping, 4, '.', ''),
            'free_shipping_applied' => $isFree,
            'free_shipping_threshold' => $rule->free_shipping_threshold,
        ];
    }
}
