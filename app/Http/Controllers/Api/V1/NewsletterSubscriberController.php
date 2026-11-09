<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class NewsletterSubscriberController extends Controller {
 public function subscribe(Request $request) {
  $data=$request->validate(['email'=>['required','email','max:255']]);
  $email=Str::lower(trim($data['email']));
  DB::transaction(function()use($email){
   $subscriber=NewsletterSubscriber::where('email',$email)->lockForUpdate()->first();
   if(!$subscriber){
    NewsletterSubscriber::create(['email'=>$email,'status'=>'subscribed',
     'subscribed_at'=>now(),'unsubscribe_token'=>Str::random(64)]);
   }elseif($subscriber->status!=='subscribed'){
    $subscriber->update(['status'=>'subscribed','subscribed_at'=>now(),
     'unsubscribed_at'=>null,'unsubscribe_token'=>Str::random(64)]);
   }
  });
  return ApiResponse::success(null,'Subscription request received.','NEWSLETTER_SUBSCRIPTION_RECEIVED');
 }
 public function unsubscribe(Request $request) {
  $data=$request->validate(['token'=>['required','string','size:64']]);
  $subscriber=NewsletterSubscriber::where('unsubscribe_token',$data['token'])->first();
  if($subscriber&&$subscriber->status==='subscribed'){
   $subscriber->update(['status'=>'unsubscribed','unsubscribed_at'=>now(),'unsubscribe_token'=>Str::random(64)]);
  }
  return ApiResponse::success(null,'Unsubscribe request processed.','NEWSLETTER_UNSUBSCRIBE_RECEIVED');
 }
 public function index(Request $request) {
  $data=$request->validate(['status'=>['sometimes',Rule::in(['subscribed','unsubscribed'])],
   'search'=>['sometimes','string','max:255']]);
  $query=NewsletterSubscriber::query();
  if(isset($data['status']))$query->where('status',$data['status']);
  if(isset($data['search']))$query->where('email','like','%'.addcslashes($data['search'],'%_\\').'%');
  $page=$query->orderByDesc('id')->paginate(PaginationMeta::perPage($request));
  return ApiResponse::success($page->items(),'Subscribers fetched.','NEWSLETTER_SUBSCRIBERS',200,PaginationMeta::from($page));
 }
 public function update(Request $request,NewsletterSubscriber $subscriber) {
  $data=$request->validate(['status'=>['required',Rule::in(['subscribed','unsubscribed'])]]);
  if($subscriber->status!==$data['status']){
   $subscriber->update(['status'=>$data['status'],
    'subscribed_at'=>$data['status']==='subscribed'?now():$subscriber->subscribed_at,
    'unsubscribed_at'=>$data['status']==='unsubscribed'?now():null,
    'unsubscribe_token'=>Str::random(64)]);
  }
  return ApiResponse::success($subscriber->refresh(),'Subscriber updated.','NEWSLETTER_SUBSCRIBER_UPDATED');
 }
}
