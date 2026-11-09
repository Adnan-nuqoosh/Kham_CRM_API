<?php
/** Local-only product reviews HTTP regression; fixture writes rolled back. */
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
    if(!$ok)throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label."\n";
};
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $path,string $method='GET',array $payload=[])use($kernel):array{
    $request=Request::create('/api/v1/'.$path,$method,$payload,[],[],['HTTP_ACCEPT'=>'application/json']);
    $response=$kernel->handle($request);
    try{return [$response->getStatusCode(),json_decode($response->getContent(),true)];}
    finally{$kernel->terminate($request,$response);}
};
DB::beginTransaction();
try{
    $source=\App\Models\Product::where('status',\App\Enums\ProductStatus::Active)->firstOrFail();
    $product=$source->replicate();
    $product->slug='review-test-'.\Illuminate\Support\Str::random(14);
    $product->name='Review Regression Fixture';
    $product->save();
    $base='storefront/products/'.$product->id.'/reviews';
    [$status,$body]=$call($base);
    if ($status !== 200 || ($body['data']['review_count'] ?? -1) !== 0 || ($body['data']['average_rating'] ?? false) !== null) {
        echo 'DEBUG empty summary HTTP='.$status.' response='.json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";
    }
    $check($status===200&&($body['data']['review_count']??-1)===0
        &&($body['data']['average_rating']??false)===null,'empty public review summary');
    $make=static fn(int $rating,string $state,string $name)=>\App\Models\ProductReview::create([
        'product_id'=>$product->id,'rating'=>$rating,'reviewer_name'=>$name,
        'verified_purchase'=>false,'status'=>$state,'body'=>'Regression fixture',
    ]);
    $approved=$make(5,'approved','Public Fixture');
    $make(1,'pending','Pending Fixture');
    $make(2,'rejected','Rejected Fixture');
    [$status,$body]=$call($base);
    $check($status===200&&($body['data']['review_count']??null)===1
        &&($body['data']['average_rating']??null)===5.0,'only approved reviews counted');
    $rows=$body['data']['reviews']??[];
    $check(count($rows)===1&&($rows[0]['id']??null)===$approved->id
        &&($rows[0]['reviewer_name']??null)==='Public Fixture',
        'pending and rejected review content hidden');
    $check(!array_key_exists('customer_id',$rows[0])&&!array_key_exists('order_item_id',$rows[0])
        &&!array_key_exists('moderated_by',$rows[0]),'public response hides internal identity fields');
    [$status]=$call($base.'?page=0');
    $check($status===422,'invalid pagination rejected');
    [$status]=$call('product-reviews');
    $check($status===401,'CRM reviews listing requires authentication');
    [$status]=$call('product-reviews','POST',['rating'=>5]);
    $check($status===401,'CRM review submission requires authentication');
    $product->forceFill(['status'=>\App\Enums\ProductStatus::Draft])->save();
    [$status]=$call($base);
    $check($status===404,'draft product reviews not public');
    echo "ALL PRODUCT REVIEW PUBLIC CHECKS PASSED (rolled back)\n";
}finally{DB::rollBack();}
