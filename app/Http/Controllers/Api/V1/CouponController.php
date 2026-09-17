<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
class CouponController extends Controller {
 public function index(){return ApiResponse::success(Coupon::latest()->paginate(30));}
 public function store(Request $r){$d=$r->validate(['code'=>'required|string|max:80|unique:coupons,code','name'=>'required|string|max:255','type'=>'required|in:fixed,percentage','value'=>'required|numeric|min:0','min_spend'=>'nullable|numeric|min:0','max_discount'=>'nullable|numeric|min:0','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at','max_uses'=>'nullable|integer|min:1','max_uses_per_customer'=>'nullable|integer|min:1','rules'=>'nullable|array','is_active'=>'boolean']);$d['code']=strtoupper($d['code']);return ApiResponse::success(Coupon::create($d),'Coupon created.','COUPON_CREATED',201);}
 public function update(Request $r,Coupon $coupon){$coupon->update($r->validate(['name'=>'sometimes|string|max:255','type'=>'sometimes|in:fixed,percentage','value'=>'sometimes|numeric|min:0','min_spend'=>'nullable|numeric|min:0','max_discount'=>'nullable|numeric|min:0','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at','max_uses'=>'nullable|integer|min:1','max_uses_per_customer'=>'nullable|integer|min:1','rules'=>'nullable|array','is_active'=>'boolean']));return ApiResponse::success($coupon->fresh(),'Coupon updated.','COUPON_UPDATED');}
}
