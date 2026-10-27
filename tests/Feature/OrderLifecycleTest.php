<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function createTestOrder(): Order
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

        $warehouse = Warehouse::create([
            'code' => 'EG-01',
            'name' => 'Test Warehouse',
            'market_id' => $market->id,
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Test Serum',
            'slug' => 'test-serum',
            'status' => 'active',
            'track_inventory' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'TEST-SERUM-001',
            'title' => '30ml',
            'is_active' => true,
        ]);

        ProductMarketPrice::create([
            'variant_id' => $variant->id,
            'market_id' => $market->id,
            'currency_id' => $currency->id,
            'price' => 350,
            'is_active' => true,
        ]);

        InventoryStock::create([
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'on_hand' => 25,
            'reserved' => 0,
            'damaged' => 0,
            'reorder_level' => 5,
        ]);

        return app(\App\Services\OrderService::class)->create([
            'market_id' => $market->id,
            'warehouse_id' => $warehouse->id,
            'items' => [
                [
                    'variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
            'shipping_total' => 50,
            'tax_total' => 0,
            'shipping_address' => [
                'first_name' => 'Test',
                'line1' => 'Test Address',
                'city' => 'Cairo',
                'country_code' => 'EG',
            ],
        ]);
    }

    public function test_order_creation_reserves_inventory(): void
    {
        $order = $this->createTestOrder();

        $stock = InventoryStock::firstOrFail();

        $this->assertEquals(OrderStatus::Pending, $order->status);
        $this->assertEquals(25, $stock->on_hand);
        $this->assertEquals(2, $stock->reserved);
        $this->assertEquals(23, $stock->available);
        $this->assertEquals(750, (float) $order->grand_total);
    }

    public function test_cancellation_releases_reserved_inventory(): void
    {
        $order = $this->createTestOrder();

        $inventory = app(\App\Services\InventoryService::class);

        $inventory->release(
            $order->items->first()->variant_id,
            $order->warehouse_id,
            2,
            $order->id
        );

        $order->update([
            'status' => OrderStatus::Cancelled,
            'fulfillment_status' => 'cancelled',
        ]);

        $stock = InventoryStock::firstOrFail()->fresh();

        $this->assertEquals(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertEquals(25, $stock->on_hand);
        $this->assertEquals(0, $stock->reserved);
        $this->assertEquals(25, $stock->available);
    }

    public function test_shipment_commits_reserved_inventory(): void
    {
        $order = $this->createTestOrder();

        $inventory = app(\App\Services\InventoryService::class);

        $inventory->commitSale(
            $order->items->first()->variant_id,
            $order->warehouse_id,
            2,
            $order->id
        );

        $order->update([
            'status' => OrderStatus::Shipped,
            'fulfillment_status' => 'shipped',
        ]);

        $stock = InventoryStock::firstOrFail()->fresh();

        $this->assertEquals(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertEquals(23, $stock->on_hand);
        $this->assertEquals(0, $stock->reserved);
        $this->assertEquals(23, $stock->available);
    }
}
