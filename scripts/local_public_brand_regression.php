<?php
/** Module 10: rollback-only public brand listing/detail HTTP regression. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local MySQL kham_crm_api only.\n");exit(2);
}
$check=static function(bool $ok,string $label):void{
 if(!$ok)throw new RuntimeException('FAIL: '.$label);
 echo "PASS: {$label}\n";
};
$send=static function(string $path,array $query=[])use($kernel):array{
 $req=Request::create('http://localhost/api/v1/storefront/'.$path,'GET',$query,[],[],
  ['HTTP_ACCEPT'=>'application/json']);
 $res=$kernel->handle($req);
 try{return [$res->getStatusCode(),json_decode($res->getContent(),true)];}
 finally{$kernel->terminate($req,$res);}
};
$count=Brand::count();
DB::beginTransaction();
try{
 $suffix=Str::lower(Str::random(12));
 $active=Brand::create(['name'=>'Brand Regression '.$suffix,'name_ar'=>'علامة تجريبية',
  'description'=>'English description','description_ar'=>'وصف تجريبي',
  'slug'=>'brand-regression-'.$suffix,'is_active'=>true]);
 $hidden=Brand::create(['name'=>'Hidden Brand '.$suffix,'slug'=>'hidden-brand-'.$suffix,
  'is_active'=>false]);
 [$status,$response]=$send('brands',['search'=>$suffix]);
 $ids=collect($response['data']??[])->pluck('id');
 $check($status===200 && $ids->contains($active->id),'active brand appears in public listing');
 $check(!$ids->contains($hidden->id),'inactive brand hidden from listing');
 [$status,$response]=$send('brands',['search'=>$suffix,'locale'=>'ar']);
 $localized=collect($response['data']??[])->firstWhere('id',$active->id);
 $check($status===200 && ($localized['name']??null)==='علامة تجريبية'
  && ($localized['description']??null)==='وصف تجريبي','Arabic brand fields localized');
 [$status]=$send('brands/'.$hidden->slug,['market_id'=>1]);
 $check($status===404,'inactive brand detail inaccessible');
 [$status]=$send('brands/'.$active->slug);
 $check($status===422,'brand detail requires market');
 $market=DB::table('markets')->where('is_active',true)->first();
 if(!$market)throw new RuntimeException('STOP: active market required');
 [$status,$response]=$send('brands/'.$active->slug,['market_id'=>$market->id]);
 $check($status===200 && ($response['data']['brand']['id']??null)===$active->id
  && is_array($response['data']['products']??null),'active brand detail returns products array');
 [$status]=$send('brands/no-such-brand-'.$suffix,['market_id'=>$market->id]);
 $check($status===404,'unknown brand slug returns 404');
 echo "ALL 7 BRAND HTTP CHECKS PASSED (rolling back)\n";
}finally{
 DB::rollBack();
 $check(Brand::count()===$count,'original brand count preserved');
}
