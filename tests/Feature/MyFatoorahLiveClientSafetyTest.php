<?php

namespace Tests\Feature;

use App\Services\MyFatoorahLiveClient;
use Illuminate\Foundation\Testing\TestCase;
use RuntimeException;

class MyFatoorahLiveClientSafetyTest extends TestCase
{
    public function test_live_gateway_is_disabled_by_default(): void
    {
        config(['commerce.myfatoorah_live_enabled' => false]);
        $this->expectException(RuntimeException::class);
        app(MyFatoorahLiveClient::class)->getPaymentDetails('EG', '123456');
    }

    public function test_checkout_must_not_accept_unknown_market(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(MyFatoorahLiveClient::class)->getPaymentDetails('XX', '123456');
    }
}
