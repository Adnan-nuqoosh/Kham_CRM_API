<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    protected $fillable = ['product_id', 'customer_id', 'order_item_id', 'rating',
        'title', 'body', 'reviewer_name', 'verified_purchase', 'status',
        'moderated_at', 'moderated_by'];
    protected $casts = ['rating' => 'integer', 'verified_purchase' => 'boolean',
        'moderated_at' => 'datetime'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
}
