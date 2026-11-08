<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\ProductVariant;
use App\Services\FulfillmentWarehouseResolver;
use App\Services\PricingService;
use App\Services\ShippingQuoteService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicCheckoutQuoteController extends Controller
{
    /**
     * Return a non-binding storefront estimate. This endpoint does not reserve
     * stock, create an order, or accept client-supplied prices or tax amounts.
     */
    public function __invoke(
        Request $request,
        PricingService $pricing,
        ShippingQuoteService $shipping,
        FulfillmentWarehouseResolver $warehouses
    ): JsonResponse {
        $data = $request->validate([
            'market_id' => ['required', 'integer', 'exists:markets,id'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.variant_id' => ['required', 'integer', 'distinct:strict', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.unit_price' => ['prohibited'],
            'coupon_code' => ['prohibited'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.country_code' => ['required', 'string', 'size:2'],
            'shipping_address.city' => ['required', 'string', 'max:120'],
        ]);

        $market = Market::with('currency')->findOrFail($data['market_id']);
        if (!$market->is_active || !$market->currency || !$market->currency->is_active) {
            throw ValidationException::withMessages([
                'market_id' => ['Selected sales market is unavailable.'],
            ]);
        }

        $warehouse = $warehouses->forMarket((int) $market->id);
        $variantIds = array_column($data['items'], 'variant_id');
        $variants = ProductVariant::with('product')->whereIn('id', $variantIds)->get()->keyBy('id');
        $stocks = InventoryStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('variant_id', $variantIds)
            ->get()
            ->keyBy('variant_id');

        $subtotal = 0.0;
        foreach ($data['items'] as $item) {
            $variantId = (int) $item['variant_id'];
            $quantity = (int) $item['quantity'];
            $variant = $variants->get($variantId);

            if (!$variant || !$variant->is_active || !$variant->product
                || $variant->product->status !== ProductStatus::Active) {
                throw ValidationException::withMessages([
                    'items' => ['One or more products are unavailable.'],
                ]);
            }

            $stock = $stocks->get($variantId);
            if (!$stock || $stock->available < $quantity) {
                throw ValidationException::withMessages([
                    'items' => ['One or more items have insufficient available stock.'],
                ]);
            }

            $price = $pricing->priceFor($variant, (int) $market->id);
            if ((int) $price->currency_id !== (int) $market->currency_id) {
                throw ValidationException::withMessages([
                    'market_id' => ['Product price currency does not match the market.'],
                ]);
            }

            $subtotal += (float) $price->price * $quantity;
        }

        $shippingQuote = $shipping->quote(
            $market,
            $data['shipping_address']['country_code'],
            $data['shipping_address']['city'],
            round($subtotal, 4)
        );
        $shippingTotal = (float) $shippingQuote['shipping_total'];

        // The public response does not expose internal shipping-rule identifiers.
        unset($shippingQuote['rule_id']);

        return ApiResponse::success([
            'market_id' => $market->id,
            'currency_code' => $market->currency->code,
            'subtotal' => number_format($subtotal, 4, '.', ''),
            'discount_total' => '0.0000',
            'net_subtotal' => number_format($subtotal, 4, '.', ''),
            'shipping' => $shippingQuote,
            'total_before_tax' => number_format($subtotal + $shippingTotal, 4, '.', ''),
            'tax_status' => 'not_configured',
            'is_final_payable_total' => false,
        ], 'Provisional quote only; tax and final payable amount are not configured.', 'STOREFRONT_QUOTE')
            ->header('Cache-Control', 'no-store');
    }
}
