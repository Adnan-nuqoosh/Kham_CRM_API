<?php
/** Local-only authenticated reviews regression. All fixture writes rolled back. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use App\Models\{Product,ProductReview,Order,OrderItem,Customer,User,Market,Warehouse};
use App\Enums\{OrderStatus,ProductStatus};
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local kham_crm_api MySQL only.\n");exit(2);
}
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $method,string $uri,array $data=[])use($kernel):array{
 $req=Request::create('/api/v1/'.$uri,$method,$data,[],[],['HTTP_ACCEPT'=>'application/json']);
 $res=$kernel->handle($req);
 try{return [$res->getStatusCode(),json_decode($res->getContent(),true)];}
 finally{$kernel->terminate($req,$res);}
};
$check=static function(bool $ok,string $label,array $result=[]) :void{
 if(!$ok)throw new RuntimeException('FAIL: '.$label.' '.json_encode($result));
 echo 'PASS: '.$label."\n";
};
DB::beginTransaction();
try{
 $source=Product::where('status',ProductStatus::Active)->firstOrFail();
 $product=$source->replicate();
 $product->slug='review-auth-'.Str::random(16);
 $product->name='Review Auth Regression';
 $product->save();
 $market=Market::query()->firstOrFail();
 $warehouse=Warehouse::query()->firstOrFail();
 $customer=Customer::create(['first_name'=>'Review','last_name'=>'Fixture']);
 $order=Order::create([
  'order_number'=>'REV-'.Str::upper(Str::random(18)),
  'customer_id'=>$customer->id,'market_id'=>$market->id,'warehouse_id'=>$warehouse->id,
  'currency_code'=>'KWD','status'=>OrderStatus::Pending,
  'payment_status'=>'pending','fulfillment_status'=>'unfulfilled',
  'subtotal'=>10,'discount_total'=>0,'shipping_total'=>0,'tax_total'=>0,'grand_total'=>10,
 ]);
 $item=OrderItem::create(['order_id'=>$order->id,'product_id'=>$product->id,
  'sku'=>'REVIEW-FIXTURE','name'=>'Review Fixture','quantity'=>1,
  'unit_price'=>10,'discount_total'=>0,'tax_total'=>0,'line_total'=>10]);
 $user=User::create(['name'=>'Review Regression Staff','email'=>'review-'.Str::random(16).'@example.invalid','password'=>Str::random(48)]);
 $view=Permission::where('name','products.view')->where('guard_name','web')->firstOrFail();
 $manage=Permission::where('name','products.manage')->where('guard_name','web')->firstOrFail();
 $user->givePermissionTo($view);
 Sanctum::actingAs($user,['*']);
 [$status,$body]=$call('POST','product-reviews',['order_item_id'=>$item->id,'rating'=>5]);
 $check($status===403,'viewer cannot submit reviews',[$status,$body]);
 $permissionProbe=ProductReview::create(['product_id'=>$product->id,'rating'=>3,
  'reviewer_name'=>'Permission Probe','verified_purchase'=>false,'status'=>'pending']);
 [$status,$body]=$call('PATCH','product-reviews/'.$permissionProbe->id.'/moderate',['status'=>'approved']);
 $check($status===403,'viewer cannot moderate reviews',[$status,$body]);
 $user->givePermissionTo($manage);
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $user->unsetRelation('permissions');
 [$status,$body]=$call('POST','product-reviews',['order_item_id'=>$item->id,'rating'=>5]);
 $check($status===422,'non-delivered order rejected',[$status,$body]);
 $order->update(['status'=>OrderStatus::Delivered]);
 [$status,$body]=$call('POST','product-reviews',['order_item_id'=>$item->id,'rating'=>6]);
 $check($status===422,'out-of-range rating rejected',[$status,$body]);
 [$status,$body]=$call('POST','product-reviews',['order_item_id'=>$item->id,'rating'=>5,'title'=>'Verified fixture']);
 $reviewId=$body['data']['id']??null;
 $check($status===201&&$reviewId!==null&&($body['data']['status']??null)==='pending'
  &&($body['data']['verified_purchase']??null)===true
  &&($body['data']['customer_id']??null)===$customer->id,'delivered purchase creates pending verified review',[$status,$body]);
 [$status,$body]=$call('POST','product-reviews',['order_item_id'=>$item->id,'rating'=>4]);
 $check($status===422,'duplicate order-item review rejected',[$status,$body]);
 [$status,$body]=$call('GET','product-reviews?status=pending&product_id='.$product->id);
 $pendingRows=$body['data']['data']??[];
 $check($status===200&&($body['data']['total']??null)===2
  &&count($pendingRows)===2
  &&count(array_filter($pendingRows,static fn($row)=>($row['status']??null)==='pending'
    &&($row['product_id']??null)===$product->id))===2
  &&in_array($reviewId,array_column($pendingRows,'id'),true),
  'CRM pending review filter',[$status,$body]);
 [$status,$body]=$call('PATCH','product-reviews/'.$reviewId.'/moderate',['status'=>'pending']);
 $check($status===422,'moderation rejects invalid status',[$status,$body]);
 [$status,$body]=$call('PATCH','product-reviews/'.$reviewId.'/moderate',['status'=>'approved']);
 $check($status===200&&($body['data']['status']??null)==='approved'
  &&($body['data']['moderated_by']??null)===$user->id,'authorized moderation records actor',[$status,$body]);
 [$status,$body]=$call('GET','storefront/products/'.$product->id.'/reviews');
 $check($status===200&&($body['data']['review_count']??null)===1,'approved review visible publicly',[$status,$body]);
 [$status,$body]=$call('PATCH','product-reviews/'.$reviewId.'/moderate',['status'=>'rejected']);
 $check($status===200&&($body['data']['status']??null)==='rejected','authorized rejection succeeds',[$status,$body]);
 [$status,$body]=$call('GET','storefront/products/'.$product->id.'/reviews');
 $check($status===200&&($body['data']['review_count']??null)===0,'rejected review disappears publicly',[$status,$body]);
 echo "ALL 11 AUTHENTICATED REVIEW CHECKS PASSED (rolled back)\n";
}finally{
 if(DB::transactionLevel()>0)DB::rollBack();
 app(PermissionRegistrar::class)->forgetCachedPermissions();
}
