<?php
/** Module 10: authenticated CRM ritual CRUD, permission and public product filtering; rollback only. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Ritual,User,Product};
use App\Enums\ProductStatus;
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
 fwrite(STDERR,"STOP: local MySQL kham_crm_api only.\n");exit(2);
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
$before=[Ritual::count(),DB::table('ritual_steps')->count(),DB::table('ritual_product')->count()];
DB::beginTransaction();
try{
 $suffix=strtolower(Str::random(14));
 $user=User::create(['name'=>'Ritual Regression','email'=>'ritual-'.$suffix.'@example.invalid','password'=>Str::random(48)]);
 $view=Permission::where('name','products.view')->where('guard_name','web')->firstOrFail();
 $manage=Permission::where('name','products.manage')->where('guard_name','web')->firstOrFail();
 $user->givePermissionTo($view);
 Sanctum::actingAs($user,['*']);
 [$status,$body]=$call('GET','rituals');
 $check($status===200,'viewer can list CRM rituals',$body??[]);
 [$status,$body]=$call('POST','rituals',['slug'=>'blocked-'.$suffix,'title'=>'Blocked']);
 $check($status===403,'viewer cannot create ritual',$body??[]);
 $user->givePermissionTo($manage);
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $user->unsetRelation('permissions');
 [$status,$body]=$call('POST','rituals',['slug'=>'bad-'.$suffix]);
 $check($status===422,'title required',$body??[]);
 $source=Product::query()->firstOrFail();
 $active=$source->replicate();
 $active->slug='ritual-active-'.$suffix;
 $active->name='Ritual Active Product';
 $active->status=ProductStatus::Active;
 $active->save();
 $draft=$source->replicate();
 $draft->slug='ritual-draft-product-'.$suffix;
 $draft->name='Ritual Draft Product';
 $draft->status=ProductStatus::Draft;
 $draft->save();
 $slug='ritual-crm-'.$suffix;
 [$status,$body]=$call('POST','rituals',[
  'slug'=>$slug,'title'=>'Morning Routine','title_ar'=>'روتين الصباح','is_published'=>true,
  'steps'=>[['title'=>'Cleanse','instructions'=>'Wash face','sort_order'=>0]],
  'products'=>[['product_id'=>$active->id],['product_id'=>$draft->id]],
 ]);
 $id=$body['data']['id']??null;
 $check($status===201&&is_int($id)&&count($body['data']['steps']??[])===1
  &&count($body['data']['products']??[])===2,'CRM creates ritual with steps and products',$body??[]);
 [$status,$body]=$call('POST','rituals',['slug'=>$slug,'title'=>'Duplicate']);
 $check($status===422,'duplicate slug rejected',$body??[]);
 [$status,$body]=$call('GET','rituals/'.$id);
 $check($status===200&&($body['data']['id']??null)===$id,'CRM ritual detail',$body??[]);
 [$status,$body]=$call('GET','storefront/rituals/'.$slug);
 $ids=collect($body['data']['products']??[])->pluck('id');
 $check($status===200&&$ids->contains($active->id)&&!$ids->contains($draft->id),
  'public detail hides draft products',$body??[]);
 [$status,$body]=$call('PATCH','rituals/'.$id,[
  'title'=>'Updated Routine','steps'=>[['title'=>'Moisturize','instructions'=>'Apply cream']],
  'products'=>[['product_id'=>$active->id]],
 ]);
 $check($status===200&&($body['data']['title']??null)==='Updated Routine'
  &&count($body['data']['steps']??[])===1
  &&($body['data']['steps'][0]['title']??null)==='Moisturize'
  &&count($body['data']['products']??[])===1,'CRM updates ritual and replaces relations',$body??[]);
 [$status,$body]=$call('PATCH','rituals/'.$id,['is_published'=>false]);
 $check($status===200&&($body['data']['is_published']??null)===false,'CRM unpublishes ritual',$body??[]);
 [$status]=$call('GET','storefront/rituals/'.$slug);
 $check($status===404,'unpublished CRM ritual hidden publicly');
 [$status,$body]=$call('DELETE','rituals/'.$id);
 $check($status===200,'CRM deletes ritual',$body??[]);
 [$status]=$call('GET','rituals/'.$id);
 $check($status===404,'deleted ritual no longer available');
 echo "ALL 12 AUTHENTICATED RITUAL CHECKS PASSED (rolling back)\n";
}finally{
 if(DB::transactionLevel()>0)DB::rollBack();
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $after=[Ritual::count(),DB::table('ritual_steps')->count(),DB::table('ritual_product')->count()];
 $check($before===$after,'original ritual counts preserved');
}
