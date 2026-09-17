<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductStoreRequest;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ProductController extends Controller {
 public function index(Request $r){$q=Product::with(['brand','category','variants.prices.market.currency','variants.stocks.warehouse','media']);if($r->filled('status'))$q->where('status',$r->status);if($r->filled('search'))$q->where(fn($x)=>$x->where('name','like','%'.$r->search.'%')->orWhereHas('variants',fn($v)=>$v->where('sku','like','%'.$r->search.'%')));$p=$q->latest()->paginate(min((int)$r->get('per_page',20),100));return ApiResponse::success($p->items(),'Products fetched.','PRODUCT_LIST',200,['pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]]);}
 public function store(ProductStoreRequest $r){$product=DB::transaction(function()use($r){$data=$r->safe()->except('variants');$p=Product::create($data);foreach($r->input('variants',[]) as $v)$p->variants()->create($v);return $p;});return ApiResponse::success($product->load(['brand','category','variants']),'Product created.','PRODUCT_CREATED',201);}
 public function show(Product $product){return ApiResponse::success($product->load(['brand','category','variants.prices.market.currency','variants.stocks.warehouse','media']));}
 public function update(ProductStoreRequest $r,Product $product){$product->update($r->safe()->except('variants'));return ApiResponse::success($product->fresh(['brand','category','variants']),'Product updated.','PRODUCT_UPDATED');}
 public function destroy(Product $product){$product->delete();return ApiResponse::success(null,'Product archived.','PRODUCT_DELETED');}
}
