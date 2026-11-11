<?php
/** Module 7: rollback-only direct inventory reservation and warehouse isolation regression. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local MySQL kham_crm_api only.\n");exit(2);
}
$check=static function(bool $ok,string $label):void{
 if(!$ok)throw new RuntimeException("FAIL: {$label}");
 echo "PASS: {$label}\n";
};
$source=ProductVariant::query()->firstOrFail();
$warehouses=DB::table('warehouses')->orderBy('id')->limit(2)->get();
if($warehouses->count()<2)throw new RuntimeException('STOP: two warehouses required.');
$originalStocks=DB::table('inventory_stocks')->count();
$originalMovements=DB::table('inventory_movements')->count();
DB::beginTransaction();
try{
 $variant=$source->replicate();
 $variant->sku='INV-REG-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12));
 $variant->save();
 $first=InventoryStock::create(['variant_id'=>$variant->id,'warehouse_id'=>$warehouses[0]->id,
  'on_hand'=>5,'reserved'=>0,'damaged'=>1]);
 $second=InventoryStock::create(['variant_id'=>$variant->id,'warehouse_id'=>$warehouses[1]->id,
  'on_hand'=>9,'reserved'=>0,'damaged'=>0]);
 $service=app(InventoryService::class);
 $id=(int)$variant->id;$a=(int)$warehouses[0]->id;$b=(int)$warehouses[1]->id;
 $reject=static function(callable $fn,string $label)use($check):void{
  try{$fn();throw new RuntimeException('FAIL: '.$label.' accepted');}
  catch(ValidationException $e){$check(true,$label);}
 };
 $check((int)$first->fresh()->available===4,'damaged stock excluded from availability');
 $service->reserve($id,$a,3);
 $check((int)$first->fresh()->reserved===3 && (int)$first->fresh()->available===1,
  'reservation reduces available but not on-hand');
 $reject(fn()=>$service->reserve($id,$a,2),'oversell beyond available rejected');
 $reject(fn()=>$service->reserve($id,$a,0),'zero quantity reservation rejected');
 $reject(fn()=>$service->reserve($id,$a,-1),'negative reservation rejected');
 $check((int)$first->fresh()->reserved===3,'failed reservations leave stock unchanged');
 $check((int)$second->fresh()->reserved===0 && (int)$second->fresh()->on_hand===9,
  'other warehouse unaffected by reservation');
 $reject(fn()=>$service->release($id,$a,4),'release beyond reserved rejected');
 $service->release($id,$a,1);
 $check((int)$first->fresh()->reserved===2 && (int)$first->fresh()->on_hand===5,
  'release restores availability without increasing on-hand');
 $reject(fn()=>$service->commitSale($id,$a,3),'sale beyond reserved rejected');
 $service->commitSale($id,$a,2);
 $check((int)$first->fresh()->reserved===0 && (int)$first->fresh()->on_hand===3,
  'sale deducts on-hand and reservation exactly once');
 $reject(fn()=>$service->commitSale($id,$a,2),'repeat sale without reservation rejected');
 $check((int)$second->fresh()->reserved===0 && (int)$second->fresh()->on_hand===9,
  'other warehouse unaffected by sale');
 $reject(fn()=>$service->reserve($id,999999999,1),'missing warehouse stock rejected');
 echo "ALL 14 DIRECT INVENTORY CHECKS PASSED (rolling back)\n";
}finally{
 DB::rollBack();
 $check(DB::table('inventory_stocks')->count()===$originalStocks
  && DB::table('inventory_movements')->count()===$originalMovements,
  'original stock and movement counts preserved');
}
