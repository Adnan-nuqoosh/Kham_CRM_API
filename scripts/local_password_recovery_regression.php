<?php
/** Module 9 rollback-only password recovery security regression (local XAMPP only). */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use App\Notifications\CrmPasswordResetNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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
$send=static function(string $path,array $payload)use($kernel):array{
 $req=Request::create('http://localhost/api/v1/auth/'.$path,'POST',[],[],[],
  ['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],
  json_encode($payload,JSON_THROW_ON_ERROR));
 $res=$kernel->handle($req);
 try{return [$res->getStatusCode(),json_decode($res->getContent(),true)];}
 finally{$kernel->terminate($req,$res);}
};
$counts=['users'=>DB::table('users')->count(),
 'tokens'=>DB::table('personal_access_tokens')->count(),
 'resets'=>DB::table('password_reset_tokens')->count()];
$oldUrl=config('commerce.password_reset_frontend_url');
DB::beginTransaction();
try{
 config()->set('commerce.password_reset_frontend_url','https://crm.example.invalid/reset-password');
 Notification::fake();
 $email='recovery-'.Str::lower(Str::random(16)).'@example.invalid';
 $user=User::create(['name'=>'Local Recovery Tester','email'=>$email,
  'password'=>'OldPassword-Example-12345']);
 $apiToken=$user->createToken('regression')->accessToken;
 [$status,$body]=$send('forgot-password',['email'=>'unknown-'.Str::random(10).'@example.invalid']);
 $check($status===200 && ($body['code']??'')==='AUTH_RESET_REQUESTED',
  'unknown email receives generic success response');
 [$status,$body]=$send('forgot-password',['email'=>$email]);
 $check($status===200 && ($body['code']??'')==='AUTH_RESET_REQUESTED',
  'registered email receives same generic response');
 $sent=Notification::assertSentTo($user,CrmPasswordResetNotification::class);
 $check($sent!==null,'registered user receives password reset notification');
 $token=Password::broker()->createToken($user);
 [$status,$body]=$send('reset-password',['email'=>$email,'token'=>'invalid-token',
  'password'=>'NewPassword-Example-12345','password_confirmation'=>'NewPassword-Example-12345']);
 $check($status===422 && ($body['code']??'')==='AUTH_RESET_INVALID',
  'invalid reset token rejected');
 $check(Hash::check('OldPassword-Example-12345',$user->fresh()->password),
  'invalid token leaves existing password unchanged');
 DB::table('password_reset_tokens')->where('email',$email)
  ->update(['created_at'=>now()->subMinutes(61)]);
 [$status,$body]=$send('reset-password',['email'=>$email,'token'=>$token,
  'password'=>'NewPassword-Example-12345','password_confirmation'=>'NewPassword-Example-12345']);
 $check($status===422 && ($body['code']??'')==='AUTH_RESET_INVALID',
  'expired token rejected');
 $token=Password::broker()->createToken($user);
 [$status,$body]=$send('reset-password',['email'=>$email,'token'=>$token,
  'password'=>'NewPassword-Example-12345','password_confirmation'=>'NewPassword-Example-12345']);
 $check($status===200 && ($body['code']??'')==='AUTH_RESET_COMPLETE',
  'valid token resets password');
 $check(Hash::check('NewPassword-Example-12345',$user->fresh()->password),
  'new password stored as secure hash');
 $check(!DB::table('personal_access_tokens')->where('id',$apiToken->id)->exists(),
  'existing Sanctum tokens revoked after reset');
 [$status,$body]=$send('reset-password',['email'=>$email,'token'=>$token,
  'password'=>'ThirdPassword-Example-12345','password_confirmation'=>'ThirdPassword-Example-12345']);
 $check($status===422 && ($body['code']??'')==='AUTH_RESET_INVALID',
  'used reset token cannot be replayed');
 echo "ALL 10 PASSWORD RECOVERY CHECKS PASSED (rolling back)\n";
}finally{
 DB::rollBack();
 config()->set('commerce.password_reset_frontend_url',$oldUrl);
 $check(DB::table('users')->count()===$counts['users']
  && DB::table('personal_access_tokens')->count()===$counts['tokens']
  && DB::table('password_reset_tokens')->count()===$counts['resets'],
  'original user, API token and reset-token counts preserved');
}
