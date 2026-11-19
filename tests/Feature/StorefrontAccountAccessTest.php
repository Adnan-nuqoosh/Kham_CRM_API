<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StorefrontAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\TestCase;

final class StorefrontAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email): StorefrontAccount
    {
        $customer=Customer::create([
            'first_name'=>'Test','last_name'=>'Customer',
            'email'=>$email,'phone'=>'+201000000000',
        ]);
        return StorefrontAccount::create([
            'customer_id'=>$customer->id,'email'=>$email,
            'password'=>'testing-password-123',
        ]);
    }

    public function test_registration_requires_confirmed_strong_password(): void
    {
        $this->postJson('/api/v1/storefront/auth/register',[
            'first_name'=>'Test','email'=>'test@example.test','phone'=>'+201000000000',
            'password'=>'weak','password_confirmation'=>'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('storefront_accounts',0);
    }

    public function test_registration_creates_customer_and_storefront_token(): void
    {
        $result=$this->postJson('/api/v1/storefront/auth/register',[
            'first_name'=>'Test','email'=>'customer@example.test','phone'=>'+201000000000',
            'password'=>'strong-test-password-123',
            'password_confirmation'=>'strong-test-password-123',
        ])->assertCreated()->assertJsonPath('code','STOREFRONT_REGISTERED');

        $token=$result->json('data.token');
        $this->withToken($token)->getJson('/api/v1/storefront/auth/me')
            ->assertOk()->assertJsonPath('data.email','customer@example.test');
    }

    public function test_customer_cannot_read_another_customers_orders(): void
    {
        $account=$this->account('one@example.test');
        $another=$this->account('two@example.test');
        $currency=\App\Models\Currency::create([
            'code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP',
            'decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market=\App\Models\Market::create([
            'code'=>'EG','name'=>'Egypt','country_code'=>'EG','currency_id'=>$currency->id,
            'timezone'=>'Africa/Cairo','is_default'=>true,'is_active'=>true,
        ]);
        $warehouse=\App\Models\Warehouse::create([
            'code'=>'EG-01','name'=>'Egypt','market_id'=>$market->id,'is_active'=>true,
        ]);
        $order=Order::create([
            'order_number'=>'KH-2026-000001','customer_id'=>$another->customer_id,
            'market_id'=>$market->id,'warehouse_id'=>$warehouse->id,'currency_code'=>'EGP',
            'status'=>'pending','payment_status'=>'pending','fulfillment_status'=>'unfulfilled',
            'subtotal'=>'100.0000','shipping_total'=>'0.0000','discount_total'=>'0.0000',
            'tax_total'=>'0.0000','grand_total'=>'100.0000','placed_at'=>now(),
        ]);
        Sanctum::actingAs($account, ['storefront']);
        $this->getJson('/api/v1/storefront/orders/'.$order->order_number)
            ->assertNotFound();
        $this->getJson('/api/v1/storefront/orders')
            ->assertOk()->assertJsonCount(0,'data');
        Sanctum::actingAs($another, ['storefront']);
        $this->getJson('/api/v1/storefront/orders/'.$order->order_number)
            ->assertOk()->assertJsonPath('data.order_number',$order->order_number);
    }

    public function test_owner_can_resume_only_pending_initiated_hosted_checkout(): void
    {
        $owner=$this->account('payment-owner@example.test');
        $stranger=$this->account('payment-stranger@example.test');
        $currency=\\App\\Models\\Currency::create([
            'code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP',
            'decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market=\\App\\Models\\Market::create([
            'code'=>'EG','name'=>'Egypt','country_code'=>'EG','currency_id'=>$currency->id,
            'timezone'=>'Africa/Cairo','is_default'=>true,'is_active'=>true,
        ]);
        $warehouse=\\App\\Models\\Warehouse::create([
            'code'=>'EG-01','name'=>'Egypt','market_id'=>$market->id,'is_active'=>true,
        ]);
        $order=Order::create([
            'order_number'=>'KH-2026-009900','customer_id'=>$owner->customer_id,
            'market_id'=>$market->id,'warehouse_id'=>$warehouse->id,
            'currency_code'=>'EGP','status'=>'pending','payment_status'=>'pending',
            'fulfillment_status'=>'unfulfilled','subtotal'=>'100.0000',
            'shipping_total'=>'0.0000','discount_total'=>'0.0000',
            'tax_total'=>'0.0000','grand_total'=>'100.0000','placed_at'=>now(),
        ]);
        \\Illuminate\\Support\\Facades\\DB::table('myfatoorah_checkout_reservations')->insert([
            'order_id'=>$order->id,
            'idempotency_key_hash'=>hash('sha256','test-recovery-key'),
            'status'=>'initiated','amount'=>'100.0000','currency_code'=>'EGP',
            'payment_method'=>'CARD','invoice_id'=>'123456789',
            'payment_url'=>'https://payment.example.test/hosted/123',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        Sanctum::actingAs($stranger,['storefront']);
        $this->getJson('/api/v1/storefront/orders/'.$order->order_number)->assertNotFound();

        Sanctum::actingAs($owner,['storefront']);
        $url='/api/v1/storefront/orders/'.$order->order_number;
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.payment_recovery.can_resume_payment',true)
            ->assertJsonPath('data.payment_recovery.payment_url','https://payment.example.test/hosted/123')
            ->assertJsonPath('data.payment_recovery.requires_reconciliation',false);

        $order->update(['payment_status'=>'paid']);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.payment_recovery.can_resume_payment',false)
            ->assertJsonPath('data.payment_recovery.payment_url',null);

        $order->update(['payment_status'=>'pending']);
        \\Illuminate\\Support\\Facades\\DB::table('myfatoorah_checkout_reservations')
            ->where('order_id',$order->id)->update(['status'=>'uncertain']);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.payment_recovery.can_resume_payment',false)
            ->assertJsonPath('data.payment_recovery.requires_reconciliation',true);
    }

    public function test_crm_user_cannot_access_storefront_addresses(): void
    {
        $admin=User::create([
            'name'=>'Admin','email'=>'admin@example.test','password'=>'a-long-password',
        ]);
        Sanctum::actingAs($admin, ['storefront']);
        $this->getJson('/api/v1/storefront/addresses')->assertForbidden();
    }

    public function test_guest_cannot_access_storefront_orders(): void
    {
        $this->getJson('/api/v1/storefront/orders')->assertUnauthorized();
    }
}
