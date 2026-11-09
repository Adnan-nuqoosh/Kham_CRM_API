<?php
/** Local-only rollback public catalog HTTP regression. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
    ||!in_array($host,['localhost','127.0.0.1'],true)
    ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
    fwrite(STDERR,"STOP: local kham_crm_api only.\n");exit(2);
}
$check=static function(bool $ok,string $label):void{
    if(!$ok)throw new RuntimeException("FAIL: ".$label);
    echo "PASS: ".$label."\n";
};
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $path)use($kernel):array{
    $request=Request::create('/api/v1/'.$path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
    $response=$kernel->handle($request);
    try{return [$response->getStatusCode(),json_decode($response->getContent(),true)];}
    finally{$kernel->terminate($request,$response);}
};
DB::beginTransaction();
try{
    $market=\App\Models\Market::with('currency')->where('is_active',true)
        ->whereHas('currency',fn($q)=>$q->where('is_active',true))->firstOrFail();
    $source=\App\Models\ProductVariant::with('product')->where('is_active',true)->get()
        ->first(fn($v)=>$v->product&&$v->product->status===\App\Enums\ProductStatus::Active);
    if(!$source)throw new RuntimeException('STOP: active product fixture required');
    $product=$source->product->replicate();
    $product->slug='public-test-'.\Illuminate\Support\Str::random(14);
    $product->name='English Fixture';
    $product->name_ar='منتج عربي';
    $product->save();
    $variant=$source->replicate();
    $variant->product_id=$product->id;
    $variant->sku='PUBLIC-'.\Illuminate\Support\Str::random(14);
    $variant->save();
    \App\Models\ProductMarketPrice::create([
        'variant_id'=>$variant->id,'market_id'=>$market->id,
        'currency_id'=>$market->currency_id,'price'=>'123.0000','is_active'=>true
    ]);
    $base='storefront/products?market_id='.$market->id;
    [$status,$list]=$call($base.'&locale=ar&search='.urlencode('منتج عربي'));
    $items=$list['data']??[];
    $row=collect($items)->firstWhere('id',$product->id);
    $check($status===200&&$row&&($row['name']??null)==='منتج عربي',
        'guest Arabic search returns localized active product');
    $check(($row['variants'][0]['price']??null)==='123.0000'
        &&!array_key_exists('cost_price',$row['variants'][0])
        &&!array_key_exists('stocks',$row['variants'][0]),
        'public variants expose price but no costs or warehouse stock');
    [$status,$detail]=$call('storefront/products/'.$product->id.'?market_id='.$market->id.'&locale=en');
    $check($status===200&&($detail['data']['name']??null)==='English Fixture',
        'guest English product detail works');
    $warehouse=app(\App\Services\FulfillmentWarehouseResolver::class)->forMarket((int)$market->id);
    \App\Models\InventoryStock::updateOrCreate(
        ['variant_id'=>$variant->id,'warehouse_id'=>$warehouse->id],
        ['on_hand'=>5,'reserved'=>2,'damaged'=>1,'reorder_level'=>0]
    );
    [$status,$stocked]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===200&&($stocked['data']['variants'][0]['in_stock']??null)===true,
        'public catalog shows available stock as boolean');
    $check(!array_key_exists('on_hand',$stocked['data']['variants'][0])
        &&!array_key_exists('reserved',$stocked['data']['variants'][0])
        &&!array_key_exists('warehouse_id',$stocked['data']['variants'][0]),
        'public stock response hides warehouse and quantities');
    \App\Models\InventoryStock::where('variant_id',$variant->id)
        ->where('warehouse_id',$warehouse->id)->update(['reserved'=>4]);
    [$status,$soldOut]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===200&&($soldOut['data']['variants'][0]['in_stock']??null)===false,
        'reserved and damaged quantities can make public variant out of stock');
    $product->forceFill(['status'=>\App\Enums\ProductStatus::Draft])->save();
    [$status]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===404,'draft product detail hidden from guests');
    [$status]=$call('storefront/products?market_id=999999999');
    $check($status===422,'invalid market rejected');
    $product->forceFill(['status'=>\App\Enums\ProductStatus::Active])->save();
    $product->forceFill(['name_ar'=>null])->save();
    [$status,$fallback]=$call('storefront/products/'.$product->id.'?market_id='.$market->id.'&locale=ar');
    $check($status===200&&($fallback['data']['name']??null)==='English Fixture',
        'missing Arabic name safely falls back to English');
    $variant->forceFill(['is_active'=>false])->save();
    [$status]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===404,'inactive variant cannot make product publicly sellable');
    $variant->forceFill(['is_active'=>true])->save();
    $price=\App\Models\ProductMarketPrice::where('variant_id',$variant->id)
        ->where('market_id',$market->id)->firstOrFail();
    $price->forceFill(['is_active'=>false])->save();
    [$status]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===404,'inactive market price hides product detail');
    $price->forceFill(['is_active'=>true,'starts_at'=>now()->addDay()])->save();
    [$status]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===404,'future price cannot publish product');
    $price->forceFill(['starts_at'=>null,'ends_at'=>now()->subDay()])->save();
    [$status]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===404,'expired price cannot publish product');
    $price->forceFill(['ends_at'=>null])->save();
    $market->forceFill(['is_active'=>false])->save();
    [$status]=$call('storefront/products/'.$product->id.'?market_id='.$market->id);
    $check($status===422,'inactive market cannot expose public product detail');
    $market->forceFill(['is_active'=>true])->save();
    [$status]=$call($base.'&page=0');
    $check($status===422,'invalid pagination rejected');
    $otherMarket=\App\Models\Market::whereKeyNot($market->id)->first();
    if($otherMarket&&$otherMarket->is_active&&$otherMarket->currency?->is_active){
        [$status]=$call('storefront/products/'.$product->id.'?market_id='.$otherMarket->id);
        $check($status===404,'price in another market does not publish product');
    }
    echo "ALL PUBLIC STOREFRONT CATALOG CHECKS PASSED (rolled back)\n";
}finally{DB::rollBack();}
