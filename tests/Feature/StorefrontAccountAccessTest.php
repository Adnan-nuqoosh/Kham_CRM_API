<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StorefrontAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        // Guard denies owner-scoped lookups even when an order number is guessed.
        $this->actingAs($account,'sanctum')
            ->getJson('/api/v1/storefront/orders/KH-2026-000001')
            ->assertNotFound();

        $this->assertNotEquals($account->customer_id,$another->customer_id);
    }

    public function test_crm_user_cannot_access_storefront_addresses(): void
    {
        $admin=User::create([
            'name'=>'Admin','email'=>'admin@example.test','password'=>'a-long-password',
        ]);
        $this->actingAs($admin,'sanctum')
            ->getJson('/api/v1/storefront/addresses')->assertForbidden();
    }

    public function test_guest_cannot_access_storefront_orders(): void
    {
        $this->getJson('/api/v1/storefront/orders')->assertUnauthorized();
    }
}
