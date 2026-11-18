<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StorefrontAccount;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StorefrontReviewSubmissionController extends Controller
{
    public function store(Request $request,string $product)
    {
        $account=$request->user();
        if (!$account instanceof StorefrontAccount || !$account->tokenCan('storefront')) abort(403);
        $data=$request->validate([
            'order_item_id'=>['required','integer','exists:order_items,id'],
            'rating'=>['required','integer','between:1,5'],
            'title'=>['nullable','string','max:150'],
            'body'=>['nullable','string','max:3000'],
        ]);
        $p=Product::query()->where('slug',$product)
            ->when(ctype_digit($product),fn($q)=>$q->orWhere('id',(int)$product))->firstOrFail();
        $item=OrderItem::with('order')->whereKey($data['order_item_id'])
            ->where('product_id',$p->id)->firstOrFail();
        if (!$item->order || (int)$item->order->customer_id !== (int)$account->customer_id
            || $item->order->status !== OrderStatus::Delivered) {
            throw ValidationException::withMessages([
                'order_item_id'=>['Only your own delivered purchases may be reviewed.'],
            ]);
        }
        $review=DB::transaction(function()use($item,$account,$data,$p){
            if(ProductReview::where('order_item_id',$item->id)->lockForUpdate()->exists()){
                throw ValidationException::withMessages(['order_item_id'=>['Review already exists for this purchase.']]);
            }
            return ProductReview::create([
                'product_id'=>$p->id,'customer_id'=>$account->customer_id,
                'order_item_id'=>$item->id,'rating'=>$data['rating'],
                'title'=>$data['title']??null,'body'=>$data['body']??null,
                'reviewer_name'=>$account->customer->full_name,
                'verified_purchase'=>true,'status'=>'pending',
            ]);
        });
        return ApiResponse::success($review,'Review submitted for moderation.','STOREFRONT_REVIEW_PENDING',201)
            ->header('Cache-Control','no-store');
    }
}
