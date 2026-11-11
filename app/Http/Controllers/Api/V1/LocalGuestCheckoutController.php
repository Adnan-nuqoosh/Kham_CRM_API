<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\ProductVariant;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalGuestCheckoutController extends Controller
{
    /**
     * Local-only test endpoint. Never collects card details or calls a gateway.
     * All writes, including idempotency and stock reservation, are atomic.
     */
    public function __invoke(Request $request, OrderService $orders): JsonResponse
    {
        if (!app()->environment('local') || !config('commerce.storefront_test_checkout_enabled', false)) {
            abort(404);
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'market_id' => ['required', 'integer', 'exists:markets,id'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.variant_id' => ['required', 'integer', 'distinct:strict', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.unit_price' => ['prohibited'],
            'coupon_code' => ['prohibited'],
            'shipping_total' => ['prohibited'],
            'tax_total' => ['prohibited'],
            'warehouse_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'payment_method' => ['required', 'in:mock_fatoora'],
            'customer' => ['required', 'array'],
            'customer.first_name' => ['required', 'string', 'max:100'],
            'customer.last_name' => ['nullable', 'string', 'max:100'],
            'customer.email' => ['required', 'email', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.first_name' => ['required', 'string', 'max:100'],
            'shipping_address.last_name' => ['nullable', 'string', 'max:100'],
            'shipping_address.phone' => ['nullable', 'string', 'max:30'],
            'shipping_address.line1' => ['required', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:30'],
            'shipping_address.country_code' => ['required', 'string', 'size:2'],
        ]);

        $key = hash('sha256', $data['idempotency_key']);
        unset($data['idempotency_key']);
        // Normalize cart order to make harmless line reordering idempotent.
        usort($data['items'], fn ($a, $b) => (int) $a['variant_id'] <=> (int) $b['variant_id']);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($data, $key, $fingerprint, $orders) {
            DB::table('storefront_checkout_attempts')->insertOrIgnore([
                'key_hash' => $key,
                'request_hash' => $fingerprint,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $attempt = DB::table('storefront_checkout_attempts')
                ->where('key_hash', $key)->lockForUpdate()->first();

            if (!$attempt || !hash_equals($attempt->request_hash, $fingerprint)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Key already belongs to another checkout request.'],
                ]);
            }
            if ($attempt->order_id !== null) {
                $existing = \App\Models\Order::findOrFail($attempt->order_id);
                return [$existing, false];
            }

            $market = Market::with('currency')->findOrFail($data['market_id']);
            if (!$market->is_active || !$market->currency || !$market->currency->is_active) {
                throw ValidationException::withMessages(['market_id' => ['Sales market unavailable.']]);
            }
            foreach ($data['items'] as $item) {
                $variant = ProductVariant::with('product')->findOrFail($item['variant_id']);
                if (!$variant->is_active || !$variant->product
                    || $variant->product->status !== ProductStatus::Active) {
                    throw ValidationException::withMessages(['items' => ['Product unavailable.']]);
                }
                $price = app(\App\Services\PricingService::class)->priceFor($variant, (int) $market->id);
                if ((int) $price->currency_id !== (int) $market->currency_id) {
                    throw ValidationException::withMessages(['items' => ['Market price currency mismatch.']]);
                }
            }

            $customer = \App\Models\Customer::create([
                'first_name' => $data['customer']['first_name'],
                'last_name' => $data['customer']['last_name'] ?? null,
                'email' => $data['customer']['email'],
                'phone' => $data['customer']['phone'],
                'marketing_opt_in' => false,
            ]);
            $shipping = $data['shipping_address'];
            $shipping['email'] = $data['customer']['email'];
            $shipping['phone'] = $shipping['phone'] ?? $data['customer']['phone'];

            $order = $orders->create([
                'market_id' => $market->id,
                'customer_id' => $customer->id,
                'items' => $data['items'],
                'shipping_mode' => 'configured',
                'tax_total' => 0,
                'shipping_address' => $shipping,
            ], true);

            $order->payments()->create([
                'provider' => 'mock_fatoora',
                'method' => 'mock',
                'status' => 'pending',
                'amount' => $order->grand_total,
                'currency_code' => $order->currency_code,
            ]);
            DB::table('storefront_checkout_attempts')->where('id', $attempt->id)
                ->update(['order_id' => $order->id, 'updated_at' => now()]);
            return [$order, true];
        }, 5);

        [$order, $created] = $result;
        return ApiResponse::success([
            'order_number' => $order->order_number,
            'market_id' => $order->market_id,
            'currency_code' => $order->currency_code,
            'amount_before_tax' => $order->grand_total,
            'payment_provider' => 'mock_fatoora',
            'payment_status' => 'pending',
            'tax_status' => 'not_configured',
            'is_final_payable_total' => false,
            'is_test_order' => true,
        ], 'Local mock checkout only. No real payment was initiated.',
            'MOCK_CHECKOUT_ORDER', $created ? 201 : 200)->header('Cache-Control', 'no-store');
    }
}
