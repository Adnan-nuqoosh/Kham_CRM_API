<?php
/** Local-only homepage CMS HTTP regression; fixture changes rolled back. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{HomepageSection,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
$host=strtolower((string)config('database.connections.mysql.host',''));
if(!app()->environment('local')||config('database.default')!=='mysql'
 ||!in_array($host,['localhost','127.0.0.1'],true)
 ||DB::connection()->getDatabaseName()!=='kham_crm_api'){
 fwrite(STDERR,"STOP: local kham_crm_api MySQL only.\n");exit(2);
}
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
$call=static function(string $method,string $uri,array $data=[])use($kernel):array{
 $req=Request::create('http://localhost/api/v1/'.$uri,$method,$data,[],[],['HTTP_ACCEPT'=>'application/json']);
 $res=$kernel->handle($req);
 try{return [$res->getStatusCode(),json_decode($res->getContent(),true)];}
 finally{$kernel->terminate($req,$res);}
};
$check=static function(bool $ok,string $label,array $body=[]):void{
 if(!$ok)throw new RuntimeException('FAIL: '.$label.' '.json_encode($body));
 echo "PASS: {$label}\n";
};
$before=HomepageSection::count();
DB::beginTransaction();
try{
 $key='regression-'.strtolower(Str::random(12));
 [$status,$body]=$call('GET','storefront/homepage');
 $check($status===200&&is_array($body['data']??null),'public homepage available',$body??[]);
 [$status,$body]=$call('GET','storefront/homepage?locale=invalid');
 $check($status===422,'invalid locale rejected',$body??[]);
 [$status,$body]=$call('GET','homepage/sections');
 $check($status===401,'anonymous CRM listing blocked',$body??[]);
 [$status,$body]=$call('POST','homepage/sections',['key'=>$key]);
 $check($status===401,'anonymous CRM create blocked',$body??[]);
 $user=User::create(['name'=>'Homepage Regression','email'=>'homepage-staff-'.Str::random(14).'@example.invalid','password'=>Str::random(48)]);
 $view=Permission::where('name','products.view')->where('guard_name','web')->firstOrFail();
 $manage=Permission::where('name','products.manage')->where('guard_name','web')->firstOrFail();
 $user->givePermissionTo($view);
 Sanctum::actingAs($user,['*']);
 [$status,$body]=$call('GET','homepage/sections');
 $check($status===200&&is_array($body['data']??null),'CRM viewer can list sections',$body??[]);
 [$status,$body]=$call('POST','homepage/sections',[
  'key'=>$key,'type'=>'hero','title_en'=>'English title'
 ]);
 $check($status===403,'CRM viewer cannot create sections',$body??[]);
 $user->givePermissionTo($manage);
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $user->unsetRelation('permissions');
 [$status,$body]=$call('POST','homepage/sections',[
  'key'=>$key,'type'=>'invalid','title_en'=>'English title'
 ]);
 $check($status===422,'invalid section type rejected',$body??[]);
 [$status,$body]=$call('POST','homepage/sections',[
  'key'=>$key,'type'=>'hero','title_en'=>'English title',
  'title_ar'=>'عنوان عربي','subtitle_en'=>'English subtitle',
  'subtitle_ar'=>'وصف عربي','sort_order'=>3,'is_published'=>false,
  'content'=>['label'=>'fixture']
 ]);
 $section=HomepageSection::where('key',$key)->first();
 $check($status===201&&$section!==null&&!$section->is_published,
  'CRM manager creates draft section',$body??[]);
 $id=$section->id;
 [$status,$body]=$call('GET','storefront/homepage?locale=en');
 $check($status===200&&!in_array($key,array_column($body['data']??[],'key'),true),
  'draft hidden from storefront',$body??[]);
 [$status,$body]=$call('POST','homepage/sections',[
  'key'=>$key,'type'=>'hero','title_en'=>'Duplicate'
 ]);
 $check($status===422,'duplicate section key rejected',$body??[]);
 [$status,$body]=$call('PATCH','homepage/sections/'.$id,[
  'is_published'=>true,'sort_order'=>1
 ]);
 $section->refresh();
 $check($status===200&&$section->is_published&&$section->sort_order===1,
  'CRM manager publishes and orders section',$body??[]);
 [$status,$body]=$call('GET','storefront/homepage?locale=en');
 $item=collect($body['data']??[])->firstWhere('key',$key);
 $check($status===200&&($item['title']??null)==='English title'
  &&!array_key_exists('is_published',$item??[]),'English storefront renders published section',$body??[]);
 [$status,$body]=$call('GET','storefront/homepage?locale=ar');
 $item=collect($body['data']??[])->firstWhere('key',$key);
 $check($status===200&&($item['title']??null)==='عنوان عربي'
  &&($item['subtitle']??null)==='وصف عربي','Arabic storefront localized',$body??[]);
 [$status,$body]=$call('DELETE','homepage/sections/'.$id);
 $check($status===200&&HomepageSection::find($id)===null,
  'CRM manager deletes section',$body??[]);
 echo "ALL 15 HOMEPAGE CMS HTTP CHECKS PASSED (rolling back)\n";
}finally{
 if(DB::transactionLevel()>0)DB::rollBack();
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $check(HomepageSection::count()===$before,'original homepage section count preserved');
}
