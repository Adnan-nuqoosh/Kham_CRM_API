<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Market;
use App\Models\Order;
use App\Models\Warehouse;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CouponRedemptionLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function market(): Market
    {
        $currency = Currency::create([
            'code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'EGP',
            'decimal_places' => 2, 'rate_to_base' => 1, 'is_base' => true,
            'is_active' => true,
        ]);
        return Market::create([
            'code' => 'EG', 'name' => 'Egypt', 'country_code' => 'EG',
            'currency_id' => $currency->id, 'timezone' => 'Africa/Cairo',
            'is_default' => true, 'is_active' => true,
        ]);
    }

    private function order(Market $market, ?int $customerId = null): Order
    {
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'EG-01'],
            ['market_id' => $market->id, 'name' => 'Egypt Main Warehouse', 'is_active' => true]
        );
        return Order::create([
            'order_number' => 'KH-TEST-'.uniqid(),
            'customer_id' => $customerId,
            'market_id' => $market->id,
            'warehouse_id' => $warehouse->id,
            'currency_code' => 'EGP', 'status' => 'pending',
            'payment_status' => 'pending', 'fulfillment_status' => 'unfulfilled',
            'subtotal' => '100.0000', 'discount_total' => '10.0000',
            'shipping_total' => '0.0000', 'tax_total' => '0.0000',
            'grand_total' => '90.0000', 'placed_at' => now(),
        ]);
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'SAVE10', 'name' => 'Save 10%',
            'type' => 'percentage', 'value' => 10,
            'is_active' => true,
        ], $attributes));
    }

    public function test_percent_coupon_is_capped_by_redemptions_and_cancelled_order_releases_slot(): void
    {
        $market = $this->market();
        $coupon = $this->coupon(['max_uses' => 1]);
        $service = app(CouponService::class);
        $this->assertSame(10.0, $service->discount('save10', 100, null, false, $market->id));
        $order = $this->order($market);
        DB::transaction(fn () => $service->redeem($coupon->code, null, $order->id, 10));
        try {
            $service->discount('SAVE10', 100, null, false, $market->id);
            $this->fail('Used coupon must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('coupon_code', $e->errors());
        }
        $order->update(['status' => 'cancelled']);
        $this->assertSame(10.0, $service->discount('SAVE10', 100, null, false, $market->id));
    }

    public function test_customer_limit_is_per_customer_not_global(): void
    {
        $market = $this->market();
        $coupon = $this->coupon(['max_uses_per_customer' => 1]);
        $a = Customer::create(['first_name' => 'A', 'email' => 'a@example.test']);
        $b = Customer::create(['first_name' => 'B', 'email' => 'b@example.test']);
        $order = $this->order($market, $a->id);
        DB::transaction(fn () => app(CouponService::class)->redeem($coupon->code, $a->id, $order->id, 10));
        $this->assertSame(10.0, app(CouponService::class)->discount('SAVE10', 100, $b->id, false, $market->id));
        $this->expectException(ValidationException::class);
        app(CouponService::class)->discount('SAVE10', 100, $a->id, false, $market->id);
    }

    public function test_fixed_coupon_requires_one_explicit_market(): void
    {
        $market = $this->market();
        $coupon = $this->coupon(['type' => 'fixed', 'value' => 25, 'rules' => ['market_ids' => [$market->id]]]);
        $this->assertSame(25.0, app(CouponService::class)->discount($coupon->code, 100, null, false, $market->id));
        $coupon->update(['rules' => ['market_ids' => [$market->id, $market->id + 1]]]);
        $this->expectException(ValidationException::class);
        app(CouponService::class)->discount($coupon->code, 100, null, false, $market->id);
    }

    public function test_public_coupon_is_off_by_default(): void
    {
        config()->set('commerce.storefront_coupons_enabled', false);
        $this->postJson('/api/v1/storefront/checkout/quote', [
            'market_id' => 1,
            'items' => [['variant_id' => 1, 'quantity' => 1]],
            'shipping_address' => ['country_code' => 'EG', 'city' => 'Cairo'],
            'coupon_code' => 'SAVE10',
        ])->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
    }
}
