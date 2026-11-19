<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Models\ShippingRule;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

final class LocalMockOrderCrmFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_mock_order_reaches_crm_orders_and_reserves_stock_exactly_once(): void
    {
        $currency = Currency::create([
            'code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP',
            'decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market = Market::create([
            'code'=>'EG','name'=>'Egypt','country_code'=>'EG','currency_id'=>$currency->id,
            'timezone'=>'Africa/Cairo','is_default'=>true,'is_active'=>true,
        ]);
        $warehouse = Warehouse::create([
            'code'=>'EG-01','market_id'=>$market->id,
            'name'=>'Egypt Main Warehouse','is_active'=>true,
        ]);
        $product = Product::create([
            'name'=>'Test Argan Oil','slug'=>'test-argan-oil',
            'status'=>'active','track_inventory'=>true,
        ]);
        $variant = ProductVariant::create([
            'product_id'=>$product->id,'sku'=>'QA-ARGAN-01',
            'title'=>'50 ml','is_active'=>true,
        ]);
        ProductMarketPrice::create([
            'variant_id'=>$variant->id,'market_id'=>$market->id,
            'currency_id'=>$currency->id,'price'=>'100.0000','is_active'=>true,
        ]);
        $stock = InventoryStock::create([
            'variant_id'=>$variant->id,'warehouse_id'=>$warehouse->id,
            'on_hand'=>10,'reserved'=>0,'damaged'=>0,
        ]);
        ShippingRule::create([
            'market_id'=>$market->id,'city_key'=>'cairo',
            'shipping_fee'=>'20.0000','is_active'=>true,
        ]);
        $payload = [
            'idempotency_key'=>'qa-mock-order-unique-key-2026',
            'market_id'=>$market->id,
            'items'=>[['variant_id'=>$variant->id,'quantity'=>2]],
            'payment_method'=>'mock_fatoora',
            'customer'=>[
                'first_name'=>'Test','last_name'=>'Buyer',
                'email'=>'mock-buyer@example.test','phone'=>'+201000000000',
            ],
            'shipping_address'=>[
                'first_name'=>'Test','last_name'=>'Buyer',
                'line1'=>'Test Street 1','city'=>'Cairo','country_code'=>'EG',
            ],
        ];
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'commerce.storefront_test_checkout_enabled'=>true,
            'commerce.configured_shipping_orders_enabled'=>false,
            'commerce.fulfillment_warehouse_code'=>'EG-01',
        ]);
        try {
            $first = $this->postJson('/api/v1/storefront/checkout/test-order',$payload)
                ->assertCreated()
                ->assertJsonPath('code','MOCK_CHECKOUT_ORDER')
                ->assertJsonPath('data.is_test_order',true)
                ->assertJsonPath('data.payment_status','pending');
            $orderNumber = $first->json('data.order_number');

            $this->postJson('/api/v1/storefront/checkout/test-order',$payload)
                ->assertOk()->assertJsonPath('data.order_number',$orderNumber);
            $this->assertDatabaseCount('orders',1);
            $this->assertDatabaseCount('payments',1);
            $this->assertDatabaseHas('orders',[
                'order_number'=>$orderNumber,
                'currency_code'=>'EGP',
                'subtotal'=>'200.0000',
                'shipping_total'=>'20.0000',
                'tax_total'=>'0.0000',
                'grand_total'=>'220.0000',
                'payment_status'=>'pending',
            ]);
            $this->assertEquals(2,$stock->fresh()->reserved);
            $this->assertEquals(10,$stock->fresh()->on_hand);

            Permission::findOrCreate('orders.view','web');
            $admin = User::create([
                'name'=>'Order Manager','email'=>'order-manager@example.test',
                'password'=>'a-very-long-password-123',
            ]);
            $admin->givePermissionTo('orders.view');
            Sanctum::actingAs($admin);
            $this->getJson('/api/v1/orders?search='.$orderNumber)
                ->assertOk()
                ->assertJsonPath('data.0.order_number',$orderNumber)
                ->assertJsonPath('data.0.payment_status','pending');
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_public_mock_checkout_is_disabled_unless_local_and_explicitly_enabled(): void
    {
        config()->set('commerce.storefront_test_checkout_enabled',false);
        $this->postJson('/api/v1/storefront/checkout/test-order',[])
            ->assertNotFound();
    }
}
