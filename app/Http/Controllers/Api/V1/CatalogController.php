<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{Brand,Category,Currency,Market,Warehouse};
use App\Support\ApiResponse;
use Illuminate\Http\Request;
class CatalogController extends Controller {
 public function categories(){return ApiResponse::success(Category::with('children')->whereNull('parent_id')->orderBy('sort_order')->get());}
 public function storeCategory(Request $r){$d=$r->validate(['parent_id'=>'nullable|exists:categories,id','name'=>'required|string|max:255','slug'=>'required|string|max:255|unique:categories,slug','description'=>'nullable|string','image_url'=>'nullable|string','sort_order'=>'integer','is_active'=>'boolean']);return ApiResponse::success(Category::create($d),'Category created.','CATEGORY_CREATED',201);}
 public function brands(){return ApiResponse::success(Brand::orderBy('name')->get());}
 public function storeBrand(Request $r){$d=$r->validate(['name'=>'required|string|max:255','slug'=>'required|string|max:255|unique:brands,slug','origin_country'=>'nullable|string|size:2','description'=>'nullable|string','logo_url'=>'nullable|string','is_active'=>'boolean']);return ApiResponse::success(Brand::create($d),'Brand created.','BRAND_CREATED',201);}
 public function currencies(){return ApiResponse::success(Currency::orderByDesc('is_base')->orderBy('code')->get());}
 public function markets(){return ApiResponse::success(Market::with('currency')->orderBy('name')->get());}
 public function warehouses(){return ApiResponse::success(Warehouse::with('market.currency')->orderBy('name')->get());}
}
