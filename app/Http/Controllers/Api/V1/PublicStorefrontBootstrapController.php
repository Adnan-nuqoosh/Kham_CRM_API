<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\ShippingRule;
use App\Services\StorefrontCheckoutReadiness;
use App\Services\StorefrontSandboxCheckoutReadiness;
use Illuminate\Support\Facades\Schema;
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

    public function settings(Request $request)
    {
        $data = $request->validate([
            'market_id' => ['sometimes', 'required', 'integer', 'exists:markets,id'],
        ]);

        // Keep the global default market distinct from the market selected
        // by a customer. Readiness and currency must follow the selection.
        $defaultMarket = Market::query()->with('currency')
            ->where('is_active', true)->where('is_default', true)->first();
        if (!$defaultMarket || !$defaultMarket->currency || !$defaultMarket->currency->is_active) {
            $defaultMarket = Market::query()->with('currency')->where('is_active', true)->orderBy('id')->get()
                ->first(fn (Market $candidate) => $candidate->currency && $candidate->currency->is_active);
        }

        $market = isset($data['market_id'])
            ? Market::query()->with('currency')->findOrFail($data['market_id'])
            : $defaultMarket;
        if ($market && (!$market->is_active || !$market->currency || !$market->currency->is_active)) {
            abort(404);
        }

        $ready = $market ? app(StorefrontCheckoutReadiness::class)->forMarket($market) : [
            'ready' => false,
            'blockers' => ['MARKET_UNAVAILABLE'],
            'tax_status' => 'market_unavailable',
            'is_final_payable_total' => false,
        ];

        $sandbox = $market ? app(StorefrontSandboxCheckoutReadiness::class)->forMarket($market) : [
            'ready'=>false, 'blockers'=>['MARKET_UNAVAILABLE'], 'tax_status'=>'market_unavailable',
            'is_final_payable_total'=>false,
        ];

        return ApiResponse::success([
            'default_market_id' => $defaultMarket?->id,
            'default_market_code' => $defaultMarket?->code,
            'market_id' => $market?->id,
            'market_code' => $market?->code,
            'currency_code' => $market?->currency?->code,
            'features' => [
                'production_checkout_enabled' => $ready['ready'],
                'sandbox_checkout_enabled' => $sandbox['ready'],
                'customer_accounts_enabled' => Schema::hasTable('storefront_accounts'),
                'wishlist_enabled' => Schema::hasTable('storefront_wishlists'),
                'coupons_enabled' => (bool) config('commerce.storefront_coupons_enabled', false),
                'local_mock_checkout' => app()->environment('local')
                    && (bool) config('commerce.storefront_test_checkout_enabled', false),
            ],
            'sandbox_checkout' => [
                'ready' => $sandbox['ready'],
                'reason_code' => $sandbox['blockers'][0] ?? null,
                'blockers' => $sandbox['blockers'],
                'tax_status' => $sandbox['tax_status'],
                'is_final_payable_total' => $sandbox['is_final_payable_total'],
            ],
            'checkout' => [
                'ready' => $ready['ready'],
                'reason_code' => $ready['blockers'][0] ?? null,
                'blockers' => $ready['blockers'],
                'tax_status' => $ready['tax_status'],
                'is_final_payable_total' => $ready['is_final_payable_total'],
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
