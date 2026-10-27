<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\StoreCustomer;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
class StoreAuthController extends Controller {
 private function customer(Request $r): StoreCustomer { $c=$r->user();abort_unless($c instanceof StoreCustomer,403,'Customer token required.');return $c; }
 public function register(Request $r){$v=$r->validate(['name'=>'required|string|max:120','email'=>'required|email|max:190|unique:store_customers,email','password'=>'required|string|min:12|confirmed','marketing_opt_in'=>'boolean']);$c=StoreCustomer::create($v);return ApiResponse::success(['customer'=>$c,'token'=>$c->createToken('storefront')->plainTextToken],'Registered.','STORE_REGISTERED',201);}
 public function login(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required|string']);$c=StoreCustomer::where('email',$v['email'])->first();if(!$c||!Hash::check($v['password'],$c->password))throw ValidationException::withMessages(['email'=>'Invalid credentials.']);return ApiResponse::success(['customer'=>$c,'token'=>$c->createToken('storefront')->plainTextToken],'Logged in.','STORE_LOGGED_IN');}
 public function me(Request $r){return ApiResponse::success($this->customer($r));}
 public function logout(Request $r){$this->customer($r);$r->user()->currentAccessToken()?->delete();return ApiResponse::success(null,'Logged out.');}
 public function addresses(Request $r){return ApiResponse::success($this->customer($r)->addresses()->latest()->get());}
 public function saveAddress(Request $r){$c=$this->customer($r);$v=$r->validate(['label'=>'nullable|string|max:100','first_name'=>'required|string|max:120','last_name'=>'nullable|string|max:120','phone'=>'required|string|max:40','line1'=>'required|string|max:255','line2'=>'nullable|string|max:255','city'=>'required|string|max:120','state'=>'nullable|string|max:120','postal_code'=>'nullable|string|max:30','country_code'=>'required|string|size:2','is_default'=>'boolean']);$a=$c->addresses()->create($v);return ApiResponse::success($a,'Address saved.','STORE_ADDRESS_CREATED',201);}
 public function deleteAddress(Request $r,int $id){$this->customer($r)->addresses()->findOrFail($id)->delete();return ApiResponse::success(null,'Address deleted.');}
 public function wishlist(Request $r){return ApiResponse::success($this->customer($r)->wishlist()->where('status','active')->get());}
 public function addWishlist(Request $r,int $product){$c=$this->customer($r);$p=\App\Models\Product::where('status','active')->findOrFail($product);$c->wishlist()->syncWithoutDetaching([$p->id]);return ApiResponse::success(null,'Wishlist updated.');}
 public function removeWishlist(Request $r,int $product){$this->customer($r)->wishlist()->detach($product);return ApiResponse::success(null,'Wishlist updated.');}
 public function orders(Request $r){return ApiResponse::success($this->customer($r)->orders()->with('items')->latest()->paginate(15));}
 public function order(Request $r,string $number){return ApiResponse::success($this->customer($r)->orders()->where('order_number',$number)->with(['items','addresses','shipments'])->firstOrFail());}
}
