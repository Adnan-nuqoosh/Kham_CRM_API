<?php
/** Module 6 badge regression: local read-only, no fixtures or migrations. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
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
 echo 'PASS: '.$label."\n";
};
$product=Product::query()->firstOrFail();
$market=DB::table('markets')->first();
if(!$market)throw new RuntimeException('Market fixture required.');
$service=app(StorefrontProductBadgeService::class);
$original=$product->created_at;
$product->created_at=now()->subDays(2);
$recent=$service->badges($product,(int)$market->id);
$check(in_array('NEW',$recent,true),'recent product receives NEW');
$product->created_at=now()->subDays(1000);
$old=$service->badges($product,(int)$market->id);
$check(!in_array('NEW',$old,true),'old product does not receive NEW');
$check(!array_diff($old,['BESTSELLER']),'badge values limited to approved vocabulary');
$check(!array_diff($recent,['NEW','BESTSELLER']),'recent badge values limited to approved vocabulary');
$product->created_at=$original;
$check(is_array($service->badges($product,(int)$market->id)),'market-scoped badge service returns an array');
echo "ALL 5 BADGE SMOKE CHECKS PASSED (read-only)\n";
