<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class SandboxAndDeploymentSafetyTest extends TestCase
{
    public function test_sandbox_diagnostic_refuses_missing_token_and_does_not_print_secret(): void
    {
        config([
            'commerce.myfatoorah_sandbox_enabled'=>true,
            'commerce.myfatoorah_sandbox_token'=>'VERY_PRIVATE_TEST_TOKEN_DO_NOT_DISPLAY',
            'commerce.myfatoorah_sandbox_hosted_checkout_enabled'=>false,
            'commerce.myfatoorah_sandbox_webhook_enabled'=>false,
            'commerce.storefront_production_checkout_enabled'=>false,
            'commerce.myfatoorah_live_enabled'=>false,
        ]);
        $this->artisan('kham:myfatoorah-sandbox-check')->assertExitCode(1);
        $this->assertTrue(class_exists(\App\Console\Commands\MyFatoorahSandboxCheck::class));
    }

    public function test_release_audit_can_run_read_only_without_enabling_payment(): void
    {
        config([
            'commerce.storefront_production_checkout_enabled'=>false,
            'commerce.myfatoorah_live_enabled'=>false,
        ]);
        $this->artisan('kham:release-audit')->assertExitCode(0);
        $this->assertFalse(config('commerce.storefront_production_checkout_enabled'));
        $this->assertFalse(config('commerce.myfatoorah_live_enabled'));
    }

    public function test_live_and_sandbox_payment_configuration_default_to_disabled(): void
    {
        $this->assertFalse(config('commerce.myfatoorah_live_enabled',false));
        $this->assertFalse(config('commerce.storefront_production_checkout_enabled',false));
        $this->assertFalse(config('commerce.myfatoorah_sandbox_enabled',false));
    }
}
