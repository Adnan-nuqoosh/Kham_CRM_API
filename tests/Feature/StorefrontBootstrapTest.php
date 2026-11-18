<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Market;
use App\Models\ShippingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

class StorefrontBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private function egypt(): Market
    {
        $currency = Currency::create([
            'code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'EGP',
            'decimal_places' => 2, 'rate_to_base' => 1,
            'is_base' => true, 'is_active' => true,
        ]);

        return Market::create([
            'code' => 'EG', 'name' => 'Egypt', 'country_code' => 'EG',
            'currency_id' => $currency->id, 'timezone' => 'Africa/Cairo',
            'is_default' => true, 'is_active' => true,
        ]);
    }

    public function test_public_markets_only_returns_active_markets(): void
    {
        $market = $this->egypt();
        $this->getJson('/api/v1/storefront/markets')
            ->assertOk()
            ->assertJsonPath('code', 'STOREFRONT_MARKETS')
            ->assertJsonPath('data.0.id', $market->id)
            ->assertJsonPath('data.0.currency_code', 'EGP');

        $market->update(['is_active' => false]);
        $this->getJson('/api/v1/storefront/markets')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_settings_never_advertises_unimplemented_production_checkout(): void
    {
        $this->egypt();

        $this->getJson('/api/v1/storefront/settings')
            ->assertOk()
            ->assertJsonPath('data.default_market_code', 'EG')
            ->assertJsonPath('data.features.production_checkout_enabled', false)
            ->assertJsonPath('data.checkout.ready', false)
            ->assertJsonPath('data.checkout.is_final_payable_total', false);
    }

    public function test_shipping_destinations_only_expose_active_rules(): void
    {
        $market = $this->egypt();

        ShippingRule::create([
            'market_id' => $market->id, 'city_key' => 'cairo',
            'shipping_fee' => 50, 'is_active' => true,
        ]);
        ShippingRule::create([
            'market_id' => $market->id, 'city_key' => '*',
            'shipping_fee' => 70, 'is_active' => true,
        ]);
        ShippingRule::create([
            'market_id' => $market->id, 'city_key' => 'alexandria',
            'shipping_fee' => 60, 'is_active' => false,
        ]);

        $this->getJson('/api/v1/storefront/shipping/destinations?market_id='.$market->id)
            ->assertOk()
            ->assertJsonPath('data.country_code', 'EG')
            ->assertJsonPath('data.cities.0', 'cairo')
            ->assertJsonCount(1, 'data.cities')
            ->assertJsonPath('data.other_cities_supported', true)
            ->assertJsonPath('data.has_shipping_rules', true);
    }

    public function test_shipping_destinations_reject_missing_market_id(): void
    {
        $this->getJson('/api/v1/storefront/shipping/destinations')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('market_id');
    }
}
