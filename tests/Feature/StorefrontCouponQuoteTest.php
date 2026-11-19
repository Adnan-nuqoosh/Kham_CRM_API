<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Currency;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Models\ShippingRule;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class StorefrontCouponQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_coupon_discounts_public_quote_and_shipping_threshold(): void
    {
        config()->set('commerce.storefront_coupons_enabled', true);
        $currency = Currency::create([
            'code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP',
            'decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market = Market::create([
            'code'=>'EG','name'=>'Egypt','country_code'=>'EG',
            'currency_id'=>$currency->id,'timezone'=>'Africa/Cairo',
            'is_default'=>true,'is_active'=>true,
        ]);
        $warehouse = Warehouse::create([
            'code'=>'EG-01','market_id'=>$market->id,
            'name'=>'Main','is_active'=>true,
        ]);
        $product = Product::create([
            'name'=>'Argan Oil','slug'=>'argan-oil','status'=>'active','track_inventory'=>true,
        ]);
        $variant = ProductVariant::create([
            'product_id'=>$product->id,'sku'=>'ARGAN-01',
            'title'=>'Standard','is_active'=>true,
        ]);
        ProductMarketPrice::create([
            'variant_id'=>$variant->id,'market_id'=>$market->id,
            'currency_id'=>$currency->id,'price'=>100,'is_active'=>true,
        ]);
        InventoryStock::create([
            'warehouse_id'=>$warehouse->id,'variant_id'=>$variant->id,
            'on_hand'=>10,'reserved'=>0,'damaged'=>0,
        ]);
        ShippingRule::create([
            'market_id'=>$market->id,'city_key'=>'*','shipping_fee'=>20,
            'free_shipping_threshold'=>95,'is_active'=>true,
        ]);
        Coupon::create([
            'code'=>'SAVE10','name'=>'Ten percent',
            'type'=>'percentage','value'=>10,'is_active'=>true,
        ]);
        $payload = [
            'market_id'=>$market->id,
            'items'=>[['variant_id'=>$variant->id,'quantity'=>1]],
            'shipping_address'=>['country_code'=>'EG','city'=>'Cairo'],
            'coupon_code'=>'SAVE10',
        ];
        $quote=$this->postJson('/api/v1/storefront/checkout/quote',$payload)
            ->assertOk()
            ->assertJsonPath('data.subtotal','100.0000')
            ->assertJsonPath('data.discount_total','10.0000')
            ->assertJsonPath('data.net_subtotal','90.0000')
            ->assertJsonPath('data.shipping.shipping_total','20.0000')
            ->assertJsonPath('data.total_before_tax','110.0000');
        $this->assertSame(false,$quote->json('data.is_final_payable_total'));
        $this->assertDatabaseCount('coupon_redemptions',0);
        $this->assertDatabaseCount('orders',0);
    }
}
