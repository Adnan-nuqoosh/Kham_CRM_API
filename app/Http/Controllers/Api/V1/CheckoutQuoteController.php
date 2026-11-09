<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Enums\ProductStatus;
use App\Models\Market;
use App\Models\ProductVariant;
use App\Services\CouponService;
use App\Services\PricingService;
use App\Services\ShippingQuoteService;
use App\Services\MarketTaxQuoteService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutQuoteController extends Controller
{
    public function __invoke(Request $request, PricingService $pricing, CouponService $coupons, ShippingQuoteService $shipping)
    {
        $data = $request->validate([
            'market_id' => ['required', 'integer', 'exists:markets,id'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.variant_id' => ['required', 'integer', 'distinct:strict', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.country_code' => ['required', 'string', 'size:2'],
            'shipping_address.city' => ['required', 'string', 'max:120'],
        ]);

        $market = Market::with('currency')->findOrFail($data['market_id']);
        if (!$market->is_active || !$market->currency || !$market->currency->is_active) {
            throw ValidationException::withMessages(['market_id' => ['Selected sales market is unavailable.']]);
        }
        $subtotal = 0.0;
        foreach ($data['items'] as $item) {
            $variant = ProductVariant::with('product')->findOrFail($item['variant_id']);
            if (!$variant->is_active || !$variant->product || $variant->product->status !== ProductStatus::Active) {
                throw ValidationException::withMessages(['items' => ['One or more products are unavailable.']]);
            }
            $price = $pricing->priceFor($variant, (int) $market->id);
            if ((int) $price->currency_id !== (int) $market->currency_id) {
                throw ValidationException::withMessages(['market_id' => ['Product price currency does not match the market.']]);
            }
            $subtotal += (float) $price->price * (int) $item['quantity'];
        }

        $discount = $coupons->discount($data['coupon_code'] ?? null, $subtotal);
        $netSubtotal = max(0, round($subtotal - $discount, 4));
        $shippingQuote = $shipping->quote(
            $market,
            $data['shipping_address']['country_code'],
            $data['shipping_address']['city'],
            $netSubtotal
        );
        $shippingTotal = (float) $shippingQuote['shipping_total'];
        $taxReadiness = app(MarketTaxQuoteService::class)->preview($market, number_format($netSubtotal, 4, '.', ''), number_format($shippingTotal, 4, '.', ''));
        return ApiResponse::success([
            'market_id' => $market->id,
            'currency_code' => $market->currency->code,
            'subtotal' => number_format($subtotal, 4, '.', ''),
            'discount_total' => number_format($discount, 4, '.', ''),
            'net_subtotal' => number_format($netSubtotal, 4, '.', ''),
            'shipping' => $shippingQuote,
            'total_before_tax' => number_format($netSubtotal + $shippingTotal, 4, '.', ''),
            'tax_status' => $taxReadiness['tax_status'],
            'tax_total' => $taxReadiness['tax_total'],
            'is_final_payable_total' => $taxReadiness['is_final_payable_total'],
        ], 'Provisional checkout quote; tax preview is non-binding and final payable remains disabled.', 'CHECKOUT_QUOTE');
    }
}
