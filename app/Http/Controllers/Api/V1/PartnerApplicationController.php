<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\PartnerApplication;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class PartnerApplicationController extends Controller {
 public function store(Request $request) {
  $data=$request->validate([
   'contact_name'=>['required','string','max:150'],
   'email'=>['required','email','max:255'],
   'phone'=>['sometimes','nullable','string','max:50'],
   'company_name'=>['required','string','max:200'],
   'website'=>['sometimes','nullable','url','max:500'],
   'partner_type'=>['required',Rule::in(['retailer','distributor','influencer','corporate','other'])],
   'message'=>['sometimes','nullable','string','max:5000'],
  ]);
  $data['email']=strtolower(trim($data['email']));
  PartnerApplication::create($data);
  return ApiResponse::success(null,'Application received.','PARTNER_APPLICATION_RECEIVED',201)
   ->header('Cache-Control','no-store');
 }
 public function index(Request $request) {
  $filters=$request->validate([
   'status'=>['sometimes',Rule::in(['pending','in_review','approved','rejected'])],
   'partner_type'=>['sometimes',Rule::in(['retailer','distributor','influencer','corporate','other'])],
  ]);
  $query=PartnerApplication::query();
  foreach($filters as $key=>$value)$query->where($key,$value);
  $page=$query->orderByDesc('id')->paginate(PaginationMeta::perPage($request));
  return ApiResponse::success($page->items(),'Partner applications fetched.',
   'PARTNER_APPLICATIONS',200,PaginationMeta::from($page))->header('Cache-Control','no-store');
 }
 public function show(PartnerApplication $application) {
  return ApiResponse::success($application,'Partner application fetched.','PARTNER_APPLICATION')
   ->header('Cache-Control','no-store');
 }
 public function update(Request $request,PartnerApplication $application) {
  $data=$request->validate([
   'status'=>['required',Rule::in(['pending','in_review','approved','rejected'])],
   'internal_notes'=>['sometimes','nullable','string','max:5000'],
  ]);
  if($application->status!==$data['status']){
   $data['reviewed_by']=$request->user()->id;
   $data['reviewed_at']=now();
  }
  $application->update($data);
  return ApiResponse::success($application->refresh(),'Partner application updated.',
   'PARTNER_APPLICATION_UPDATED')->header('Cache-Control','no-store');
 }
}
