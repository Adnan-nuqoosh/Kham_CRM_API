<?php
/** Module 10 ritual content: local-only rollback HTTP regression. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Ritual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local MySQL kham_crm_api only.\n");exit(2);
}
foreach(['rituals','ritual_steps','ritual_product'] as $table){
 if(!Schema::hasTable($table)){fwrite(STDERR,"STOP: approved ritual migration required.\n");exit(2);}
}
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $method,string $path,array $params=[])use($kernel):array{
 $req=Request::create('http://localhost/api/v1/'.$path,$method,$params,[],[],
  ['HTTP_ACCEPT'=>'application/json']);
 $res=$kernel->handle($req);
 try{return [$res->getStatusCode(),json_decode($res->getContent(),true)];}
 finally{$kernel->terminate($req,$res);}
};
$check=static function(bool $ok,string $name):void{
 if(!$ok)throw new RuntimeException('FAIL: '.$name);
 echo "PASS: {$name}\n";
};
$before=[Ritual::count(),DB::table('ritual_steps')->count(),DB::table('ritual_product')->count()];
DB::beginTransaction();
try{
 $suffix=strtolower(Str::random(12));
 $draft=Ritual::create(['slug'=>'ritual-draft-'.$suffix,'title'=>'Draft Ritual','is_published'=>false]);
 $live=Ritual::create(['slug'=>'ritual-live-'.$suffix,'title'=>'Daily Ritual',
  'title_ar'=>'طقس يومي','description'=>'Daily steps','description_ar'=>'خطوات يومية',
  'is_published'=>true]);
 $live->steps()->create(['title'=>'Cleanse','title_ar'=>'تنظيف',
  'instructions'=>'Use cleanser','instructions_ar'=>'استخدم المنظف','sort_order'=>0]);
 [$status,$list]=$call('GET','storefront/rituals');
 $ids=collect($list['data']??[])->pluck('id');
 $check($status===200&&$ids->contains($live->id),'published ritual listed');
 $check(!$ids->contains($draft->id),'draft ritual hidden');
 [$status,$detail]=$call('GET','storefront/rituals/'.$live->slug,['locale'=>'ar']);
 $check($status===200&&($detail['data']['title']??null)==='طقس يومي'
  &&($detail['data']['steps'][0]['title']??null)==='تنظيف'
  &&($detail['data']['steps'][0]['instructions']??null)==='استخدم المنظف',
  'Arabic ritual and ordered steps');
 [$status,$detail]=$call('GET','storefront/rituals/'.$live->slug,['locale'=>'en']);
 $check($status===200&&($detail['data']['title']??null)==='Daily Ritual'
  &&is_array($detail['data']['products']??null),'English ritual and products array');
 [$status]=$call('GET','storefront/rituals/'.$draft->slug);
 $check($status===404,'draft detail inaccessible');
 [$status]=$call('GET','storefront/rituals/unknown-'.$suffix);
 $check($status===404,'unknown slug returns 404');
 [$status]=$call('POST','rituals',['slug'=>'unauthorized-'.$suffix,'title'=>'Nope']);
 $check(in_array($status,[401,403],true),'anonymous CRM write blocked');
 $live->update(['is_published'=>false]);
 [$status]=$call('GET','storefront/rituals/'.$live->slug);
 $check($status===404,'unpublished ritual becomes inaccessible');
 echo "ALL 8 RITUAL HTTP CHECKS PASSED (rolling back)\n";
}finally{
 DB::rollBack();
 $after=[Ritual::count(),DB::table('ritual_steps')->count(),DB::table('ritual_product')->count()];
 $check($before===$after,'original ritual table counts preserved');
}
