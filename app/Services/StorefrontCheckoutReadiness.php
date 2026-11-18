<?php

namespace App\Services;

use App\Models\Market;
use App\Models\ShippingRule;
use Illuminate\Support\Facades\Schema;

final class StorefrontCheckoutReadiness
{
    public function forMarket(Market $market): array
    {
        $market->loadMissing('currency');
        $tax=app(MarketTaxQuoteService::class)->readiness($market);
        $blockers=[];

        if (!app()->environment('production')
            || !config('commerce.storefront_production_checkout_enabled',false)) {
            $blockers[]='PRODUCTION_CHECKOUT_NOT_ENABLED';
        }
        if (!config('commerce.myfatoorah_live_enabled',false)) {
            $blockers[]='PAYMENT_PROVIDER_DISABLED';
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
        $region=(string)$market->code;
        if (!in_array($region,['EG','AE','SA'],true)
            || trim((string)config('commerce.myfatoorah_live_tokens.'.$region,''))==='') {
            $blockers[]='PAYMENT_MARKET_NOT_CONFIGURED';
        }
        if (trim((string)config('commerce.myfatoorah_live_webhook_secrets.'.$region,''))==='') {
            $blockers[]='PAYMENT_WEBHOOK_NOT_CONFIGURED';
        }
        $redirect=(string)config('commerce.myfatoorah_live_redirect_url','');
        if (!filter_var($redirect,FILTER_VALIDATE_URL)
            || parse_url($redirect,PHP_URL_SCHEME)!=='https') {
            $blockers[]='PAYMENT_REDIRECT_NOT_CONFIGURED';
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
