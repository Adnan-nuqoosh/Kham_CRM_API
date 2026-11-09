<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderRefundRequest extends Model
{
    protected $fillable = ['order_id', 'payment_id', 'amount', 'currency_code', 'reason', 'requested_by'];
    protected $casts = ['amount' => 'decimal:4', 'approved_at' => 'datetime'];
}
