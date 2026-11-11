<?php
/**
 * Module 5 public guest checkout boundary regression.
 * Read-only HTTP requests: invalid quotes and disabled local checkout only.
 */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local kham_crm_api only.\n");exit(2);
}
if(config('commerce.storefront_test_checkout_enabled')){
 fwrite(STDERR,"STOP: disable COMMERCE_STOREFRONT_TEST_CHECKOUT_ENABLED before running boundary tests.\n");exit(2);
}
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $uri,array $payload)use($kernel):array{
 $request=Request::create('/api/v1/'.$uri,'POST',$payload,[],[],['HTTP_ACCEPT'=>'application/json']);
 $response=$kernel->handle($request);
 try{return [$response->getStatusCode(),json_decode($response->getContent(),true)];}
 finally{$kernel->terminate($request,$response);}
};
$check=static function(bool $ok,string $label,array $result=[]):void{
 if(!$ok)throw new RuntimeException('FAIL: '.$label.' '.json_encode($result));
 echo 'PASS: '.$label."\n";
};
$before=[];
foreach(['orders','customers','payments','storefront_checkout_attempts'] as $table){
 $before[$table]=DB::table($table)->count();
}
[$status,$body]=$call('storefront/checkout/quote',[]);
$check($status===422,'public quote requires market and cart',[$status,$body]);
[$status,$body]=$call('storefront/checkout/quote',[
 'market_id'=>999999999,'items'=>[['variant_id'=>999999999,'quantity'=>1]],
 'shipping_address'=>['country_code'=>'EG','city'=>'Cairo'],
]);
$check($status===422,'public quote rejects invalid market and variant',[$status,$body]);
[$status,$body]=$call('storefront/checkout/quote',[
 'market_id'=>999999999,'items'=>[['variant_id'=>999999999,'quantity'=>1,'unit_price'=>0.01]],
 'shipping_address'=>['country_code'=>'EG','city'=>'Cairo'],
]);
$check($status===422&&isset($body['errors']['items.0.unit_price']),
 'public quote rejects client-supplied unit price',[$status,$body]);
[$status,$body]=$call('storefront/checkout/quote',[
 'market_id'=>999999999,'items'=>[['variant_id'=>999999999,'quantity'=>1]],
 'coupon_code'=>'FREE','shipping_address'=>['country_code'=>'EG','city'=>'Cairo'],
]);
$check($status===422&&isset($body['errors']['coupon_code']),
 'public quote rejects client-supplied coupon code',[$status,$body]);
[$status,$body]=$call('storefront/checkout/test-order',[]);
$check($status===404,'local test-order route is disabled by default',[$status,$body]);
foreach($before as $table=>$count){
 $check(DB::table($table)->count()===$count,$table.' row count unchanged');
}
echo "ALL 9 GUEST CHECKOUT BOUNDARY CHECKS PASSED (read-only)\n";
