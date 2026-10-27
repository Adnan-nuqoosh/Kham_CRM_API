<?php
namespace Tests\Feature;
use App\Models\{Currency,Market,StoreCustomer,StoreMarketRule};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class StorefrontTest extends TestCase {
 use RefreshDatabase;
 public function test_authentication_is_required_for_customer_data():void {$this->getJson('/api/v1/store/addresses')->assertUnauthorized();}
 public function test_checkout_is_off_by_default():void {
  $customer=StoreCustomer::create(['name'=>'Test','email'=>'test@example.com','password'=>'VerySecure1234!']);Sanctum::actingAs($customer);
  $this->postJson('/api/v1/store/checkout',[])->assertStatus(503)->assertJsonPath('code','CHECKOUT_DISABLED');
 }
 public function test_market_rules_do_not_allow_checkout_until_enabled():void {
  $currency=Currency::create(['code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP','decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true]);
  $market=Market::create(['code'=>'EG','name'=>'Egypt','country_code'=>'EG','currency_id'=>$currency->id,'timezone'=>'Africa/Cairo','is_default'=>true,'is_active'=>true]);
  StoreMarketRule::create(['market_id'=>$market->id,'shipping_flat'=>50,'tax_percent'=>14,'tax_inclusive'=>false,'cod_enabled'=>true,'is_active'=>true]);
  $totals=app(\App\Services\StoreTotalsService::class)->calculate($market,1000,0);
  $this->assertEquals(1190,$totals['grand_total']);
 }
 public function test_customer_cannot_create_admin_products():void {
  $customer=StoreCustomer::create(['name'=>'Test','email'=>'test@example.com','password'=>'VerySecure1234!']);Sanctum::actingAs($customer);
  $this->postJson('/api/v1/products',['name'=>'Bad Product'])->assertForbidden();
 }
}
