<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Market;
use App\Models\MarketTaxRule;
use App\Models\User;
use App\Services\MarketTaxQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

class MarketTaxCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function setupMarket(string $method, bool $taxShipping = false): Market
    {
        $currency = Currency::create([
            'code' => 'EGP',
            'name' => 'Egyptian Pound',
            'symbol' => 'EGP',
            'decimal_places' => 2,
            'rate_to_base' => 1,
            'is_base' => true,
            'is_active' => true,
        ]);
        $market = Market::create([
            'code' => 'EG',
            'name' => 'Egypt',
            'country_code' => 'EG',
            'currency_id' => $currency->id,
            'timezone' => 'Africa/Cairo',
            'is_default' => true,
            'is_active' => true,
        ]);
        $approver = User::create([
            'name' => 'Finance Approver',
            'email' => 'finance@example.test',
            'password' => 'test-password-not-for-production',
        ]);
        MarketTaxRule::create([
            'market_id' => $market->id,
            'rate_percent' => 10,
            'calculation_method' => $method,
            'tax_shipping' => $taxShipping,
        ])->forceFill([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => $approver->id,
        ])->save();
        return $market;
    }

    public function test_exclusive_tax_is_calculated_on_net_subtotal(): void
    {
        $market = $this->setupMarket('exclusive');
        $result = app(MarketTaxQuoteService::class)->preview($market, '100.0000', '20.0000');
        $this->assertSame('10.0000', $result['tax_total']);
        $this->assertSame('exclusive', $result['tax_calculation_method']);
        $this->assertFalse($result['is_final_payable_total']);
    }

    public function test_inclusive_tax_is_extracted_from_price_without_adding_it_again(): void
    {
        $market = $this->setupMarket('inclusive');
        $result = app(MarketTaxQuoteService::class)->preview($market, '110.0000', '20.0000');
        $this->assertSame('10.0000', $result['tax_total']);
        $this->assertSame('inclusive', $result['tax_calculation_method']);
        $this->assertFalse($result['is_final_payable_total']);
    }

    public function test_exclusive_tax_includes_shipping_only_when_policy_requires_it(): void
    {
        $market = $this->setupMarket('exclusive', true);
        $result = app(MarketTaxQuoteService::class)->preview($market, '100.0000', '20.0000');
        $this->assertSame('12.0000', $result['tax_total']);
        $this->assertFalse($result['is_final_payable_total']);
    }

    public function test_missing_policy_is_not_checkout_ready(): void
    {
        $market = $this->setupMarket('exclusive');
        MarketTaxRule::where('market_id', $market->id)->delete();
        $result = app(MarketTaxQuoteService::class)->preview($market, '100.0000', '20.0000');
        $this->assertSame('not_configured', $result['tax_status']);
        $this->assertNull($result['tax_total']);
        $this->assertFalse($result['is_final_payable_total']);
    }
}
