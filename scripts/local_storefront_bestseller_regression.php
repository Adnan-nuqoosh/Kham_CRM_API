<?php
/** Local rollback-only BESTSELLER delivered-unit and market isolation regression. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Product;
use App\Services\StorefrontProductBadgeService;
use Illuminate\Support\Facades\DB;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local kham_crm_api only.\n");exit(2);
}
$check=static function(bool $ok,string $label):void{
 if(!$ok)throw new RuntimeException("FAIL: {$label}");
 echo "PASS: {$label}\n";
};
$markets=DB::table('markets')->orderBy('id')->limit(2)->get();
if($markets->count()<2)throw new RuntimeException('STOP: two markets required for isolation regression.');
$product=Product::query()->firstOrFail();
$warehouse=DB::table('warehouses')->orderBy('id')->first();
if(!$warehouse)throw new RuntimeException('STOP: warehouse required.');
$original=config('commerce.badges');
DB::beginTransaction();
try{
 $service=app(StorefrontProductBadgeService::class);
 $baseline=DB::table('order_items')->join('orders','orders.id','=','order_items.order_id')
  ->where('order_items.product_id',$product->id)->where('orders.market_id',$markets[0]->id)
  ->where('orders.status','delivered')->where('orders.placed_at','>=',now()->subDays(90))
  ->sum('order_items.quantity');
 $threshold=(int)$baseline+3;
 config()->set('commerce.badges.bestseller_min_delivered_units',$threshold);
 config()->set('commerce.badges.bestseller_lookback_days',90);
 $has=static fn(int $marketId):bool=>in_array('BESTSELLER',$service->badges($product,$marketId),true);
 $check(!$has((int)$markets[0]->id),'baseline below configured threshold');
 $add=static function(int $marketId,string $status,int $quantity,int $daysAgo)use($product,$warehouse):void{
  $market=DB::table('markets')->where('id',$marketId)->first();
  $code='BADGE-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(16));
  $now=now();
  $orderId=DB::table('orders')->insertGetId([
   'order_number'=>$code,'market_id'=>$marketId,'warehouse_id'=>$warehouse->id,
   'currency_code'=>DB::table('currencies')->where('id',$market->currency_id)->value('code')??'USD',
   'status'=>$status,'payment_status'=>'pending','fulfillment_status'=>'unfulfilled',
   'subtotal'=>$quantity,'grand_total'=>$quantity,'placed_at'=>$now->copy()->subDays($daysAgo),
   'created_at'=>$now,'updated_at'=>$now,
  ]);
  DB::table('order_items')->insert([
   'order_id'=>$orderId,'product_id'=>$product->id,'sku'=>'BADGE-TEST','name'=>'Badge regression fixture',
   'quantity'=>$quantity,'unit_price'=>1,'line_total'=>$quantity,'created_at'=>$now,'updated_at'=>$now,
  ]);
 };
 $add((int)$markets[0]->id,'pending',10,1);
 $check(!$has((int)$markets[0]->id),'pending orders excluded');
 $add((int)$markets[0]->id,'cancelled',10,1);
 $check(!$has((int)$markets[0]->id),'cancelled orders excluded');
 $add((int)$markets[0]->id,'returned',10,1);
 $check(!$has((int)$markets[0]->id),'returned orders excluded');
 $add((int)$markets[0]->id,'delivered',10,100);
 $check(!$has((int)$markets[0]->id),'old delivered orders excluded');
 $add((int)$markets[0]->id,'delivered',2,1);
 $check(!$has((int)$markets[0]->id),'delivered units below threshold excluded');
 $add((int)$markets[0]->id,'delivered',1,1);
 $check($has((int)$markets[0]->id),'delivered units meeting threshold earn BESTSELLER');
 $before=$has((int)$markets[1]->id);
 $add((int)$markets[0]->id,'delivered',20,1);
 $check($has((int)$markets[1]->id)===$before,'other market unaffected by delivered sales');
 echo "ALL 8 BESTSELLER REGRESSION CHECKS PASSED (rolling back)\n";
}finally{
 config()->set('commerce.badges',$original);
 DB::rollBack();
 echo "PASS: Test orders and items rolled back\n";
}
