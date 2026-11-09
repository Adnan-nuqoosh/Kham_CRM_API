<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductReviewController extends Controller
{
    public function publicIndex(Request $request, Product $product)
    {
        abort_unless($product->status === ProductStatus::Active, 404);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $reviews = ProductReview::where('product_id', $product->id)->where('status', 'approved')
            ->latest('id')->paginate((int)($data['per_page'] ?? 10));
        $summary = ProductReview::where('product_id', $product->id)->where('status', 'approved')
            ->selectRaw('COUNT(*) as review_count, AVG(rating) as average_rating')->first();
        return ApiResponse::success([
            'reviews' => $reviews->getCollection()->map(fn ($r) => [
                'id' => $r->id, 'rating' => $r->rating, 'title' => $r->title,
                'body' => $r->body, 'reviewer_name' => $r->reviewer_name,
                'verified_purchase' => $r->verified_purchase,
                'created_at' => $r->created_at,
            ])->all(),
            'review_count' => (int)$summary->review_count,
            'average_rating' => $summary->average_rating === null ? null : round((float)$summary->average_rating, 2),
        ], 'Product reviews fetched.', 'PRODUCT_REVIEWS', 200, [
            'current_page' => $reviews->currentPage(),
            'last_page' => $reviews->lastPage(),
            'total' => $reviews->total(),
        ])->header('Cache-Control', 'no-store');
    }

    public function adminIndex(Request $request)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
        ]);
        $query = ProductReview::query()->with('product:id,name');
        if (isset($data['status'])) $query->where('status', $data['status']);
        if (isset($data['product_id'])) $query->where('product_id', $data['product_id']);
        return ApiResponse::success($query->latest('id')->paginate(20));
    }

    /**
     * CRM-only review intake. Identity and verified-purchase flag are derived
     * from a delivered order item, never accepted from the request payload.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:3000'],
        ]);
        $item = OrderItem::with('order.customer')->findOrFail($data['order_item_id']);
        if (!$item->product_id || !$item->order || !$item->order->customer_id ||
            $item->order->status !== OrderStatus::Delivered) {
            throw ValidationException::withMessages([
                'order_item_id' => ['Review requires a delivered order item linked to a customer and product.'],
            ]);
        }
        if (ProductReview::where('order_item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['order_item_id' => ['This order item already has a review.']]);
        }
        $customer = $item->order->customer;
        $name = trim((string)$customer->full_name);
        $review = DB::transaction(fn () => ProductReview::create([
            'product_id' => $item->product_id,
            'customer_id' => $item->order->customer_id,
            'order_item_id' => $item->id,
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'reviewer_name' => $name !== '' ? mb_substr($name, 0, 120) : 'Verified customer',
            'verified_purchase' => true,
            'status' => 'pending',
        ]));
        return ApiResponse::success($review, 'Review submitted for moderation.', 'REVIEW_PENDING', 201);
    }

    public function moderate(Request $request, ProductReview $review)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);
        $review->update(['status' => $data['status'], 'moderated_at' => now(),
            'moderated_by' => $request->user()->id]);
        return ApiResponse::success($review->refresh(), 'Review moderated.', 'REVIEW_MODERATED');
    }
}
