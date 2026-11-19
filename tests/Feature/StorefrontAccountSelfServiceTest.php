<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\StorefrontAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

final class StorefrontAccountSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createCustomerAccount(): StorefrontAccount
    {
        $customer = Customer::create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'customer@example.test',
            'phone' => '+201000000000',
        ]);
        return StorefrontAccount::create([
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'password' => 'initial-password-123',
        ]);
    }

    public function test_customer_can_update_own_profile_but_cannot_change_email(): void
    {
        $account = $this->createCustomerAccount();
        Sanctum::actingAs($account, ['storefront']);
        $this->patchJson('/api/v1/storefront/auth/me', [
            'first_name' => 'Updated',
            'phone' => '+201111111111',
        ])->assertOk()->assertJsonPath('code', 'STOREFRONT_PROFILE_UPDATED');
        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'first_name' => 'Updated']);
        $this->patchJson('/api/v1/storefront/auth/me', [
            'email' => 'attacker@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('storefront_accounts', ['id' => $account->id, 'email' => 'customer@example.test']);
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $account = $this->createCustomerAccount();
        Sanctum::actingAs($account, ['storefront']);
        $this->postJson('/api/v1/storefront/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'updated-password-123',
            'password_confirmation' => 'updated-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('initial-password-123', $account->fresh()->password));
    }

    public function test_change_password_accepts_correct_current_password(): void
    {
        $account = $this->createCustomerAccount();
        Sanctum::actingAs($account, ['storefront']);
        $this->postJson('/api/v1/storefront/auth/change-password', [
            'current_password' => 'initial-password-123',
            'password' => 'updated-password-123',
            'password_confirmation' => 'updated-password-123',
        ])->assertOk()->assertJsonPath('code', 'STOREFRONT_PASSWORD_CHANGED');
        $this->assertTrue(Hash::check('updated-password-123', $account->fresh()->password));
    }

    public function test_password_recovery_is_disabled_without_valid_https_reset_url(): void
    {
        config()->set('commerce.storefront_password_reset_frontend_url', null);
        $this->postJson('/api/v1/storefront/auth/forgot-password', [
            'email' => 'customer@example.test',
        ])->assertStatus(503)->assertJsonPath('code', 'STOREFRONT_RESET_UNAVAILABLE');
    }

    public function test_invalid_reset_token_cannot_change_password(): void
    {
        $account = $this->createCustomerAccount();
        $this->postJson('/api/v1/storefront/auth/reset-password', [
            'email' => $account->email,
            'token' => 'invalid-token',
            'password' => 'updated-password-123',
            'password_confirmation' => 'updated-password-123',
        ])->assertUnprocessable()->assertJsonPath('code', 'STOREFRONT_RESET_INVALID');
        $this->assertTrue(Hash::check('initial-password-123', $account->fresh()->password));
    }
}
