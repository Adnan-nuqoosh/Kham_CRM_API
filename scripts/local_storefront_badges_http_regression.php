<?php
/** Module 6 storefront badge HTTP + bestseller query regression; read-only. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Services\StorefrontProductBadgeService;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local kham_crm_api only.\n");exit(2);
}
$check=static function(bool $ok,string $label):void{
 if(!$ok)throw new RuntimeException('FAIL: '.$label);
 echo "PASS: {$label}\n";
};
$market=DB::table('markets')->where('is_active',1)->first();
if(!$market)throw new RuntimeException('Need an active market.');
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$get=static function(string $path)use($kernel):array{
 $request=Request::create('http://localhost'.$path,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json']);
 $response=$kernel->handle($request);
 try{return [$response->getStatusCode(),json_decode($response->getContent(),true)];}
 finally{$kernel->terminate($request,$response);}
};
[$status,$list]=$get('/api/v1/storefront/products?market_id='.$market->id);
$check($status===200 && ($list['code']??null)==='STOREFRONT_PRODUCTS','public listing returns successfully');
$items=$list['data']??[];
$check(is_array($items),'public listing data is an array');
foreach($items as $item){
 $check(isset($item['badges']) && is_array($item['badges'])
  && !array_diff($item['badges'],['NEW','BESTSELLER'])
  && count($item['badges'])===count(array_unique($item['badges'])),
  'listing product '.$item['id'].' has valid badges');
}
if($items){
 $item=$items[0];
 [$status,$detail]=$get('/api/v1/storefront/products/'.$item['id'].'?market_id='.$market->id);
 $check($status===200 && ($detail['code']??null)==='STOREFRONT_PRODUCT','public detail returns successfully');
 $check(($detail['data']['badges']??null)===$item['badges'],'detail and listing badges match');
 $product=Product::findOrFail($item['id']);
 $expected=app(StorefrontProductBadgeService::class)->badges($product,(int)$market->id);
 $check($expected===$item['badges'],'listing matches server badge service');
} else {
 echo "SKIP: detail comparison (no sellable products in selected market)\n";
}
echo "STOREFRONT BADGE HTTP CHECKS PASSED (read-only)\n";
