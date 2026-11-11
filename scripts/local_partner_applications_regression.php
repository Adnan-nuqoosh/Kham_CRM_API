<?php
/** Local-only Partner Applications HTTP regression; fixtures rolled back. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{PartnerApplication,User};
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
$before=PartnerApplication::count();
DB::beginTransaction();
try{
 $email='partner-'.strtolower(Str::random(14)).'@example.invalid';
 [$status,$body]=$call('POST','storefront/partners/apply',['email'=>'bad']);
 $check($status===422,'invalid application rejected',$body??[]);
 [$status,$body]=$call('POST','storefront/partners/apply',[
  'contact_name'=>'Fixture Person','email'=>strtoupper($email),
  'company_name'=>'Fixture Partner','partner_type'=>'invalid'
 ]);
 $check($status===422,'invalid partner type rejected',$body??[]);
 [$status,$body]=$call('POST','storefront/partners/apply',[
  'contact_name'=>'Fixture Person','email'=>strtoupper($email),
  'company_name'=>'Fixture Partner','partner_type'=>'retailer',
  'message'=>'Interested in partnership'
 ]);
 $application=PartnerApplication::where('email',$email)->first();
 $check($status===201&&$application!==null&&$application->status==='pending'
  &&!array_key_exists('email',$body['data']??[]),'public submission creates pending application without PII response',$body??[]);
 $id=$application->id;
 [$status,$body]=$call('GET','partner-applications');
 $check($status===401,'anonymous CRM listing blocked',$body??[]);
 [$status,$body]=$call('GET','partner-applications/'.$id);
 $check($status===401,'anonymous CRM detail blocked',$body??[]);
 [$status,$body]=$call('PATCH','partner-applications/'.$id,['status'=>'approved']);
 $check($status===401,'anonymous CRM review blocked',$body??[]);
 $user=User::create(['name'=>'Partner Regression','email'=>'partner-staff-'.Str::random(14).'@example.invalid','password'=>Str::random(48)]);
 $view=Permission::where('name','customers.view')->where('guard_name','web')->firstOrFail();
 $manage=Permission::where('name','customers.manage')->where('guard_name','web')->firstOrFail();
 $user->givePermissionTo($view);
 Sanctum::actingAs($user,['*']);
 [$status,$body]=$call('GET','partner-applications?status=pending&partner_type=retailer');
 $rows=$body['data']??[];
 $check($status===200&&is_array($rows)&&in_array($id,array_column($rows,'id'),true),
  'CRM viewer can filter applications',$body??[]);
 [$status,$body]=$call('GET','partner-applications/'.$id);
 $check($status===200&&($body['data']['id']??null)===$id,'CRM viewer can see application detail',$body??[]);
 [$status,$body]=$call('PATCH','partner-applications/'.$id,['status'=>'approved']);
 $check($status===403,'CRM viewer cannot review applications',$body??[]);
 $user->givePermissionTo($manage);
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $user->unsetRelation('permissions');
 [$status,$body]=$call('PATCH','partner-applications/'.$id,['status'=>'unknown']);
 $check($status===422,'invalid review status rejected',$body??[]);
 [$status,$body]=$call('PATCH','partner-applications/'.$id,[
  'status'=>'in_review','internal_notes'=>'Follow up required'
 ]);
 $application->refresh();
 $check($status===200&&$application->status==='in_review'
  &&$application->reviewed_by===$user->id&&$application->reviewed_at!==null
  &&$application->internal_notes==='Follow up required','CRM review records actor and notes',$body??[]);
 [$status,$body]=$call('PATCH','partner-applications/'.$id,['status'=>'approved']);
 $check($status===200&&($body['data']['status']??null)==='approved',
  'CRM manager approves application',$body??[]);
 [$status,$body]=$call('GET','partner-applications?status=approved&partner_type=retailer');
 $rows=$body['data']??[];
 $check($status===200&&is_array($rows)&&in_array($id,array_column($rows,'id'),true),
  'approved application appears in filtered CRM listing',$body??[]);
 echo "ALL 12 PARTNER APPLICATION HTTP CHECKS PASSED (rolling back)\n";
}finally{
 if(DB::transactionLevel()>0)DB::rollBack();
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $check(PartnerApplication::count()===$before,'original partner application count preserved');
}
