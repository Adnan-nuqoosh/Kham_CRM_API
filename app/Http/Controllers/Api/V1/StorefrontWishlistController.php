<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StorefrontAccount;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StorefrontWishlistController extends Controller
{
    private function customerId(Request $request): int
    {
        $user=$request->user();
        if (!$user instanceof StorefrontAccount || !$user->tokenCan('storefront')) abort(403);
        return $user->customer_id;
    }

    public function index(Request $request)
    {
        $id=$this->customerId($request);
        $products=Product::query()->where('status',ProductStatus::Active)
            ->whereIn('id',DB::table('storefront_wishlists')->where('customer_id',$id)->select('product_id'))
            ->orderBy('id')->get(['id','slug','name','name_ar']);
        return ApiResponse::success($products,'Wishlist fetched.','STOREFRONT_WISHLIST')
            ->header('Cache-Control','no-store');
    }

    public function store(Request $request)
    {
        $id=$this->customerId($request);
        $data=$request->validate(['product_id'=>['required','integer','exists:products,id']]);
        $product=Product::whereKey($data['product_id'])->where('status',ProductStatus::Active)->firstOrFail();
        DB::table('storefront_wishlists')->insertOrIgnore([
            'customer_id'=>$id,'product_id'=>$product->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return ApiResponse::success(['product_id'=>$product->id],'Wishlist updated.','STOREFRONT_WISHLIST_UPDATED')
            ->header('Cache-Control','no-store');
    }

    public function destroy(Request $request,int $product)
    {
        DB::table('storefront_wishlists')->where('customer_id',$this->customerId($request))
            ->where('product_id',$product)->delete();
        return ApiResponse::success(null,'Wishlist item removed.','STOREFRONT_WISHLIST_REMOVED')
            ->header('Cache-Control','no-store');
    }
}
