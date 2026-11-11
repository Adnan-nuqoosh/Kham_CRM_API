<?php
/** Local-only newsletter HTTP regression; all fixtures rolled back. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{NewsletterSubscriber,User};
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
$before=NewsletterSubscriber::count();
DB::beginTransaction();
try{
 $email='newsletter-'.strtolower(Str::random(14)).'@example.invalid';
 [$status,$body]=$call('POST','storefront/newsletter/subscribe',['email'=>'bad-email']);
 $check($status===422,'invalid email rejected',$body??[]);
 [$status,$body]=$call('POST','storefront/newsletter/subscribe',['email'=>strtoupper($email)]);
 $subscriber=NewsletterSubscriber::where('email',$email)->first();
 $check($status===200&&$subscriber!==null&&$subscriber->status==='subscribed'
  &&strlen($subscriber->unsubscribe_token)===64,'public subscribe normalizes email',$body??[]);
 $id=$subscriber->id;
 $token=$subscriber->unsubscribe_token;
 [$status,$body]=$call('POST','storefront/newsletter/subscribe',['email'=>$email]);
 $check($status===200&&NewsletterSubscriber::where('email',$email)->count()===1
  &&NewsletterSubscriber::find($id)->unsubscribe_token===$token,'duplicate subscribe idempotent',$body??[]);
 [$status,$body]=$call('POST','storefront/newsletter/unsubscribe',['token'=>'invalid']);
 $check($status===422,'invalid unsubscribe token rejected',$body??[]);
 [$status,$body]=$call('POST','storefront/newsletter/unsubscribe',['token'=>str_repeat('x',64)]);
 $check($status===200&&NewsletterSubscriber::find($id)->status==='subscribed',
  'unknown unsubscribe token is neutral',$body??[]);
 [$status,$body]=$call('POST','storefront/newsletter/unsubscribe',['token'=>$token]);
 $subscriber->refresh();
 $check($status===200&&$subscriber->status==='unsubscribed'
  &&$subscriber->unsubscribed_at!==null&&$subscriber->unsubscribe_token!==$token,
  'valid unsubscribe changes status and rotates token',$body??[]);
 [$status,$body]=$call('POST','storefront/newsletter/unsubscribe',['token'=>$token]);
 $check($status===200&&NewsletterSubscriber::find($id)->status==='unsubscribed',
  'replayed unsubscribe token is neutral',$body??[]);
 [$status,$body]=$call('POST','storefront/newsletter/subscribe',['email'=>$email]);
 $subscriber->refresh();
 $check($status===200&&$subscriber->status==='subscribed'
  &&$subscriber->unsubscribed_at===null,'resubscribe restores status',$body??[]);
 [$status,$body]=$call('GET','newsletter/subscribers');
 $check($status===401,'anonymous CRM listing blocked',$body??[]);
 [$status,$body]=$call('PATCH','newsletter/subscribers/'.$id,['status'=>'unsubscribed']);
 $check($status===401,'anonymous CRM update blocked',$body??[]);
 $user=User::create(['name'=>'Newsletter Regression','email'=>'newsletter-staff-'.Str::random(14).'@example.invalid','password'=>Str::random(48)]);
 $view=Permission::where('name','customers.view')->where('guard_name','web')->firstOrFail();
 $manage=Permission::where('name','customers.manage')->where('guard_name','web')->firstOrFail();
 $user->givePermissionTo($view);
 Sanctum::actingAs($user,['*']);
 [$status,$body]=$call('GET','newsletter/subscribers?status=subscribed&search='.$email);
 $rows=$body['data']??[];
 $check($status===200&&is_array($rows)&&in_array($id,array_column($rows,'id'),true),
  'CRM viewer can filter subscribers',$body??[]);
 [$status,$body]=$call('PATCH','newsletter/subscribers/'.$id,['status'=>'unsubscribed']);
 $check($status===403,'CRM viewer cannot update subscriber',$body??[]);
 $user->givePermissionTo($manage);
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $user->unsetRelation('permissions');
 [$status,$body]=$call('PATCH','newsletter/subscribers/'.$id,['status'=>'invalid']);
 $check($status===422,'CRM invalid status rejected',$body??[]);
 [$status,$body]=$call('PATCH','newsletter/subscribers/'.$id,['status'=>'unsubscribed']);
 $check($status===200&&($body['data']['status']??null)==='unsubscribed'
  &&NewsletterSubscriber::find($id)->unsubscribed_at!==null,
  'CRM manager unsubscribes subscriber',$body??[]);
 [$status,$body]=$call('PATCH','newsletter/subscribers/'.$id,['status'=>'subscribed']);
 $check($status===200&&($body['data']['status']??null)==='subscribed'
  &&NewsletterSubscriber::find($id)->unsubscribed_at===null,
  'CRM manager resubscribes subscriber',$body??[]);
 echo "ALL 15 NEWSLETTER HTTP CHECKS PASSED (rolling back)\n";
}finally{
 if(DB::transactionLevel()>0)DB::rollBack();
 app(PermissionRegistrar::class)->forgetCachedPermissions();
 $check(NewsletterSubscriber::count()===$before,'original newsletter count preserved');
}
