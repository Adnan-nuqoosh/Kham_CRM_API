<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\StorefrontAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Laravel\Sanctum\Sanctum;

final class StorefrontCheckoutReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_customer_cannot_checkout(): void
    {
        $this->postJson('/api/v1/storefront/checkout',[])
            ->assertUnauthorized();
    }

    public function test_checkout_is_disabled_without_independent_release_switch(): void
    {
        $customer=Customer::create([
            'first_name'=>'Test','email'=>'buyer@example.test','phone'=>'+2010000000',
        ]);
        $account=StorefrontAccount::create([
            'customer_id'=>$customer->id,'email'=>'buyer@example.test',
            'password'=>'strong-test-password-123',
        ]);
        Sanctum::actingAs($account,['storefront']);
        config([
            'commerce.storefront_production_checkout_enabled'=>false,
            'commerce.myfatoorah_live_enabled'=>false,
        ]);
        $this->postJson('/api/v1/storefront/checkout',[])
            ->assertStatus(503)->assertJsonPath('code','CHECKOUT_DISABLED');
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('payments',0);
    }

    public function test_live_webhook_is_not_publicly_operational_during_testing(): void
    {
        $this->postJson('/api/v1/storefront/payments/myfatoorah/live-webhook/EG',[
            'Event'=>['Code'=>1,'Name'=>'PAYMENT_STATUS_CHANGED'],
        ])->assertNotFound();
    }
}
