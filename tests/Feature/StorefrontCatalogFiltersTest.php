<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\Product;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

final class StorefrontCatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function setupCatalog(): array
    {
        $currency=Currency::create([
            'code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP',
            'decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market=Market::create([
            'code'=>'EG','name'=>'Egypt','country_code'=>'EG','currency_id'=>$currency->id,
            'timezone'=>'Africa/Cairo','is_default'=>true,'is_active'=>true,
        ]);
        $warehouse=Warehouse::create([
            'code'=>'EG-01','market_id'=>$market->id,'name'=>'Egypt main','is_active'=>true,
        ]);
        $brand=Brand::create(['name'=>'KHAM','slug'=>'kham','is_active'=>true]);
        $category=Category::create([
            'name'=>'Skincare','slug'=>'skincare','sort_order'=>1,'is_active'=>true,
        ]);
        $results=[];
        foreach([['alpha',100],['beta',250]] as [$slug,$price]){
            $product=Product::create([
                'brand_id'=>$brand->id,'category_id'=>$category->id,
                'name'=>ucfirst($slug),'slug'=>$slug,'status'=>'active',
                'track_inventory'=>true,
            ]);
            $variant=ProductVariant::create([
                'product_id'=>$product->id,'sku'=>'TEST-'.strtoupper($slug),
                'title'=>'Standard','is_active'=>true,
            ]);
            ProductMarketPrice::create([
                'variant_id'=>$variant->id,'market_id'=>$market->id,
                'currency_id'=>$currency->id,'price'=>$price,'is_active'=>true,
            ]);
            InventoryStock::create([
                'warehouse_id'=>$warehouse->id,'variant_id'=>$variant->id,
                'on_hand'=>10,'reserved'=>0,'damaged'=>0,
            ]);
            $results[]=$product;
        }
        return [$market,$results[0],$results[1]];
    }

    public function test_product_detail_resolves_slug_and_id(): void
    {
        [$market,$alpha]=$this->setupCatalog();
        $this->getJson('/api/v1/storefront/products/alpha?market_id='.$market->id)
            ->assertOk()->assertJsonPath('data.slug','alpha')
            ->assertJsonPath('data.variants.0.sku','TEST-ALPHA')
            ->assertJsonPath('data.variants.0.available_qty',10);
        $this->getJson('/api/v1/storefront/products/'.$alpha->id.'?market_id='.$market->id)
            ->assertOk()->assertJsonPath('data.slug','alpha');
    }

    public function test_catalog_filters_and_price_sort(): void
    {
        [$market]=$this->setupCatalog();
        $url='/api/v1/storefront/products?market_id='.$market->id;
        $this->getJson($url.'&brand=kham&category=skincare&sort=price_desc')
            ->assertOk()->assertJsonCount(2,'data')
            ->assertJsonPath('data.0.slug','beta')
            ->assertJsonPath('data.1.slug','alpha');
        $this->getJson($url.'&brand=missing')
            ->assertOk()->assertJsonCount(0,'data');
    }
}
