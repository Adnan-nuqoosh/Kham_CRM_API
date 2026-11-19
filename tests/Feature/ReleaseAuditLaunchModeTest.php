<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class ReleaseAuditLaunchModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_ready_strict_fails_with_disabled_payment_and_mail_without_exposing_secrets(): void
    {
        config([
            'commerce.storefront_production_checkout_enabled'=>false,
            'commerce.myfatoorah_live_enabled'=>false,
            'commerce.configured_shipping_orders_enabled'=>false,
            'commerce.myfatoorah_live_tokens.EG'=>'SENSITIVE_LIVE_TOKEN_DO_NOT_DISPLAY',
            'commerce.myfatoorah_live_webhook_secrets.EG'=>'SENSITIVE_WEBHOOK_SECRET_DO_NOT_DISPLAY',
            'commerce.password_reset_frontend_url'=>null,
            'commerce.storefront_password_reset_frontend_url'=>null,
            'mail.default'=>'log',
        ]);
        $this->artisan('kham:release-audit --launch-ready --strict')
            ->expectsOutputToContain('[FAIL] Production payment gate enabled by operator')
            ->expectsOutputToContain('[FAIL] MyFatoorah live flag enabled by operator')
            ->expectsOutputToContain('[FAIL] CRM reset URL HTTPS')
            ->expectsOutputToContain('[FAIL] Mail transport configured (verify delivery separately)')
            ->assertExitCode(1);
        $this->assertFalse(config('commerce.myfatoorah_live_enabled'));
    }

    public function test_safe_prelaunch_mode_is_still_read_only(): void
    {
        config([
            'commerce.storefront_production_checkout_enabled'=>false,
            'commerce.myfatoorah_live_enabled'=>false,
        ]);
        $this->artisan('kham:release-audit')->assertExitCode(0);
    }
}
