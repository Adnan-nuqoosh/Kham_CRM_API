<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\MarketTaxRule;
use App\Models\Product;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Models\ShippingRule;
use App\Models\StorefrontAccount;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

final class StorefrontProviderUncertainTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_503_leaves_one_reservation_and_blocks_automatic_retry(): void
    {
        $currency=Currency::create([
            'code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP',
            'decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market=Market::create([
            'code'=>'EG','name'=>'Egypt','country_code'=>'EG',
            'currency_id'=>$currency->id,'timezone'=>'Africa/Cairo',
            'is_default'=>true,'is_active'=>true,
        ]);
        $warehouse=Warehouse::create([
            'code'=>'EG-01','market_id'=>$market->id,
            'name'=>'Egypt Fulfillment','is_active'=>true,
        ]);
        $product=Product::create([
            'name'=>'Test Product','slug'=>'test-product','status'=>'active',
            'track_inventory'=>true,
        ]);
        $variant=ProductVariant::create([
            'product_id'=>$product->id,'sku'=>'TEST-001','title'=>'Default','is_active'=>true,
        ]);
        ProductMarketPrice::create([
            'variant_id'=>$variant->id,'market_id'=>$market->id,
            'currency_id'=>$currency->id,'price'=>'100.0000','is_active'=>true,
        ]);
        $stock=InventoryStock::create([
            'variant_id'=>$variant->id,'warehouse_id'=>$warehouse->id,
            'on_hand'=>10,'reserved'=>0,'damaged'=>0,
        ]);
        ShippingRule::create([
            'market_id'=>$market->id,'city_key'=>'cairo',
            'shipping_fee'=>'20.0000','is_active'=>true,
        ]);
        $approver=User::create([
            'name'=>'Finance','email'=>'finance@test.invalid',
            'password'=>'testing-password-123',
        ]);
        $rule=MarketTaxRule::create([
            'market_id'=>$market->id,'rate_percent'=>'10.0000',
            'calculation_method'=>'exclusive','tax_shipping'=>false,
        ]);
        $rule->forceFill([
            'is_approved'=>true,'approved_at'=>now(),'approved_by'=>$approver->id,
        ])->save();

        $customer=Customer::create([
            'first_name'=>'Customer','email'=>'customer@test.invalid',
            'phone'=>'+201000000000',
        ]);
        $account=StorefrontAccount::create([
            'customer_id'=>$customer->id,'email'=>'customer@test.invalid',
            'password'=>'testing-password-123',
        ]);
        Sanctum::actingAs($account,['storefront']);
        $this->app->detectEnvironment(fn ()=>'production');
        config([
            'commerce.storefront_production_checkout_enabled'=>true,
            'commerce.myfatoorah_live_enabled'=>true,
            'commerce.configured_shipping_orders_enabled'=>true,
            'commerce.myfatoorah_live_tokens.EG'=>'fake-not-real',
            'commerce.myfatoorah_live_webhook_secrets.EG'=>'fake-secret',
            'commerce.myfatoorah_live_redirect_url'=>'https://example.test/payment/result',
            'commerce.fulfillment_warehouse_code'=>'EG-01',
        ]);
        Http::fake([
            'https://api-eg.myfatoorah.com/v3/payments'=>Http::response([
                'IsSuccess'=>false,
                'Message'=>'Test upstream unavailable',
            ],503),
        ]);
        $payload=[
            'idempotency_key'=>'checkout-key-123456789',
            'market_id'=>$market->id,
            'payment_method'=>'CARD',
            'items'=>[['variant_id'=>$variant->id,'quantity'=>2]],
            'shipping_address'=>[
                'first_name'=>'Customer','line1'=>'Street 1',
                'city'=>'Cairo','country_code'=>'EG',
            ],
        ];
        try {
            $first=$this->postJson('/api/v1/storefront/checkout',$payload)
                ->assertStatus(503)->assertJsonPath('code','PAYMENT_INITIATION_UNCERTAIN');

            $this->assertDatabaseCount('orders',1);
            $this->assertDatabaseCount('payments',0);
            $this->assertDatabaseHas('myfatoorah_checkout_reservations',['status'=>'uncertain']);
            $this->assertSame(2,(int)$stock->fresh()->reserved);

            $second=$this->postJson('/api/v1/storefront/checkout',$payload)
                ->assertStatus(202)->assertJsonPath('code','CHECKOUT_RECONCILIATION_REQUIRED')
                ->assertJsonPath('data.initiation_status','uncertain');
            $this->assertDatabaseCount('orders',1);
            $this->assertDatabaseCount('myfatoorah_checkout_reservations',1);
            $this->assertSame(2,(int)$stock->fresh()->reserved);
            Http::assertSentCount(1);
        } finally {
            $this->app->detectEnvironment(fn ()=>'testing');
        }
    }
}
