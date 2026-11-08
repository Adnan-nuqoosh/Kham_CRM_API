<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRule extends Model
{
    protected $fillable = [
        'market_id', 'city_key', 'shipping_fee', 'free_shipping_threshold', 'is_active',
    ];

    protected $casts = [
        'shipping_fee' => 'decimal:4',
        'free_shipping_threshold' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
