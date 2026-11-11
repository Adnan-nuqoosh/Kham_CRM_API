<?php
/** Local-only weighted recommendation HTTP regression; rolls back all fixtures. */
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
$check=static function(bool $ok,string $name):void{
    if(!$ok)throw new RuntimeException("FAIL: ".$name);
    echo "PASS: ".$name."\n";
};
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(array $payload)use($kernel):array{
    $request=Request::create('/api/v1/storefront/ritual-quiz/recommendations','POST',$payload,[],[],['HTTP_ACCEPT'=>'application/json']);
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
    if(!$source)throw new RuntimeException('STOP: active product variant fixture required');
    $products=[];
    foreach([1,2,3] as $n){
        $p=$source->product->replicate();
        $p->slug='quiz-test-'.\Illuminate\Support\Str::random(14);
        $p->name='Quiz Fixture '.$n;
        $p->name_ar='اختبار '.$n;
        $p->save();
        $v=$source->replicate();
        $v->product_id=$p->id;
        $v->sku='QUIZ-'.\Illuminate\Support\Str::random(14);
        $v->save();
        \App\Models\ProductMarketPrice::create([
            'variant_id'=>$v->id,'market_id'=>$market->id,'currency_id'=>$market->currency_id,
            'price'=>'100.0000','is_active'=>true,
        ]);
        $products[]=$p;
    }
    $q=\App\Models\RitualQuizQuestion::create(['question'=>'Skin type?','is_active'=>true]);
    $o=$q->options()->create(['label'=>'Dry','is_active'=>true]);
    $o->products()->sync([
        $products[0]->id=>['weight'=>2],
        $products[1]->id=>['weight'=>9],
        $products[2]->id=>['weight'=>5],
    ]);
    $payload=['market_id'=>$market->id,'option_ids'=>[$o->id]];
    [$status,$body]=$call($payload);
    $ids=array_column($body['data']??[],'id');
    $check($status===200&&array_slice($ids,0,3)===[$products[1]->id,$products[2]->id,$products[0]->id],
        'recommendations ranked by descending weight');
    $check(($body['data'][0]['score']??null)===9,'weighted score returned');
    $products[1]->forceFill(['status'=>\App\Enums\ProductStatus::Draft])->save();
    [$status,$body]=$call($payload);
    $ids=array_column($body['data']??[],'id');
    $check($status===200&&!in_array($products[1]->id,$ids,true),'draft product excluded');
    $products[2]->variants()->update(['is_active'=>false]);
    [$status,$body]=$call($payload);
    $ids=array_column($body['data']??[],'id');
    $check($status===200&&$ids===[$products[0]->id],'inactive variant excluded');
    $o->forceFill(['is_active'=>false])->save();
    [$status]=$call($payload);
    $check($status===422,'inactive answer rejected');
    echo "ALL WEIGHTED RITUAL QUIZ CHECKS PASSED (rolled back)\n";
}finally{DB::rollBack();}
