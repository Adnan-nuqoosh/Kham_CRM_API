<?php

namespace App\Services;

use App\Models\Market;
use App\Models\ShippingRule;
use Illuminate\Support\Facades\Schema;

final class StorefrontSandboxCheckoutReadiness
{
    public function forMarket(Market $market): array
    {
        $market->loadMissing('currency');
        $tax=app(MarketTaxQuoteService::class)->readiness($market);
        $blockers=[];

        if (!app()->environment('local','testing','staging')
            || !config('commerce.storefront_sandbox_checkout_enabled',false)) {
            $blockers[]='SANDBOX_CHECKOUT_DISABLED';
        }
        if (!config('commerce.myfatoorah_sandbox_enabled',false)) {
            $blockers[]='SANDBOX_PROVIDER_DISABLED';
        }
        if (!config('commerce.myfatoorah_sandbox_webhook_enabled',false)) {
            $blockers[]='SANDBOX_WEBHOOK_DISABLED';
        }
        if (!config('commerce.configured_shipping_orders_enabled',false)) {
            $blockers[]='CONFIGURED_SHIPPING_ORDERS_DISABLED';
        }
        if (!$market->is_active || !$market->currency || !$market->currency->is_active) {
            $blockers[]='MARKET_UNAVAILABLE';
        }
        if (!ShippingRule::where('market_id',$market->id)->where('is_active',true)->exists()) {
            $blockers[]='SHIPPING_NOT_CONFIGURED';
        }
        if (($tax['tax_status']??null)!=='approved_not_enabled') {
            $blockers[]='TAX_POLICY_NOT_APPROVED';
        }
        if (trim((string)config('commerce.myfatoorah_sandbox_token',''))==='') {
            $blockers[]='SANDBOX_TOKEN_MISSING';
        }
        if (trim((string)config('commerce.myfatoorah_webhook_secret',''))==='') {
            $blockers[]='SANDBOX_WEBHOOK_SECRET_MISSING';
        }
        $redirect=(string)config('commerce.myfatoorah_sandbox_redirect_url','');
        if (!filter_var($redirect,FILTER_VALIDATE_URL)
            || parse_url($redirect,PHP_URL_SCHEME)!=='https') {
            $blockers[]='SANDBOX_REDIRECT_NOT_CONFIGURED';
        }
        if (!Schema::hasTable('storefront_accounts') || !Schema::hasTable('storefront_wishlists')) {
            $blockers[]='STOREFRONT_MIGRATIONS_MISSING';
        }

        return [
            'ready'=>count($blockers)===0,
            'blockers'=>$blockers,
            'tax_status'=>$tax['tax_status']??'not_configured',
            'is_final_payable_total'=>count($blockers)===0,
        ];
    }
}
