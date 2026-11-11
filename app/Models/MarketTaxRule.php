<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketTaxRule extends Model
{
    protected $fillable = ['market_id', 'rate_percent', 'calculation_method', 'tax_shipping'];
    protected $casts = [
        'rate_percent' => 'decimal:4',
        'tax_shipping' => 'boolean',
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
