<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\ShippingRule;
use App\Services\MarketTaxQuoteService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

final class PublicStorefrontBootstrapController extends Controller
{
    public function markets()
    {
        $markets = Market::query()->with('currency')
            ->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get()
            ->filter(fn (Market $market) => $market->currency && $market->currency->is_active)
            ->map(fn (Market $market) => [
                'id' => $market->id, 'code' => $market->code, 'name' => $market->name,
                'country_code' => $market->country_code,
                'currency_code' => $market->currency->code,
                'is_default' => (bool) $market->is_default,
            ])->values()->all();

        return ApiResponse::success($markets, 'Active storefront markets fetched.', 'STOREFRONT_MARKETS')
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function settings()
    {
        $market = Market::query()->with('currency')
            ->where('is_active', true)->where('is_default', true)->first();
        if (!$market || !$market->currency || !$market->currency->is_active) {
            $market = Market::query()->with('currency')->where('is_active', true)->orderBy('id')->get()
                ->first(fn (Market $candidate) => $candidate->currency && $candidate->currency->is_active);
        }
        $ready = $market ? app(MarketTaxQuoteService::class)->readiness($market) : null;

        return ApiResponse::success([
            'default_market_id' => $market?->id,
            'default_market_code' => $market?->code,
            'currency_code' => $market?->currency?->code,
            'features' => [
                'production_checkout_enabled' => false,
                'customer_accounts_enabled' => false,
                'wishlist_enabled' => false,
                'coupons_enabled' => false,
                'local_mock_checkout' => app()->environment('local')
                    && (bool) config('commerce.storefront_test_checkout_enabled', false),
            ],
            'checkout' => [
                'ready' => false,
                'reason_code' => 'PRODUCTION_CHECKOUT_NOT_ENABLED',
                'tax_status' => $ready['tax_status'] ?? 'market_unavailable',
                'is_final_payable_total' => false,
            ],
        ], 'Storefront settings fetched.', 'STOREFRONT_SETTINGS')
            ->header('Cache-Control', 'no-store');
    }

    public function destinations(Request $request)
    {
        $data = $request->validate(['market_id' => ['required', 'integer', 'exists:markets,id']]);
        $market = Market::query()->with('currency')->findOrFail($data['market_id']);
        abort_unless($market->is_active && $market->currency && $market->currency->is_active, 404);

        $rules = ShippingRule::query()->where('market_id', $market->id)
            ->where('is_active', true)->orderBy('city_key')->get();
        return ApiResponse::success([
            'market_id' => $market->id,
            'country_code' => strtoupper($market->country_code),
            'currency_code' => $market->currency->code,
            'cities' => $rules->where('city_key', '!=', '*')->pluck('city_key')->values()->all(),
            'other_cities_supported' => $rules->contains('city_key', '*'),
            'has_shipping_rules' => $rules->isNotEmpty(),
        ], 'Configured shipping destinations fetched.', 'STOREFRONT_DESTINATIONS')
            ->header('Cache-Control', 'no-store');
    }
}
