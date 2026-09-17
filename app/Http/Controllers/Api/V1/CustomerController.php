<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
class CustomerController extends Controller {
 public function index(Request $r){$q=Customer::withCount('orders')->withSum('orders','grand_total');if($r->filled('search'))$q->where(fn($x)=>$x->where('email','like','%'.$r->search.'%')->orWhere('phone','like','%'.$r->search.'%')->orWhere('first_name','like','%'.$r->search.'%'));return ApiResponse::success($q->latest()->paginate(min((int)$r->get('per_page',20),100)));}
 public function store(Request $r){$d=$r->validate(['first_name'=>'required|string|max:120','last_name'=>'nullable|string|max:120','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:50','status'=>'nullable|string|max:20','tags'=>'nullable|array','notes'=>'nullable|string','marketing_opt_in'=>'boolean']);return ApiResponse::success(Customer::create($d),'Customer created.','CUSTOMER_CREATED',201);}
 public function show(Customer $customer){return ApiResponse::success($customer->load(['addresses','orders.items']));}
 public function update(Request $r,Customer $customer){$customer->update($r->validate(['first_name'=>'sometimes|string|max:120','last_name'=>'nullable|string|max:120','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:50','status'=>'nullable|string|max:20','tags'=>'nullable|array','notes'=>'nullable|string','marketing_opt_in'=>'boolean']));return ApiResponse::success($customer->fresh(),'Customer updated.','CUSTOMER_UPDATED');}
}
