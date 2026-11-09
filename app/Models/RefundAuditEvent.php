<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundAuditEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['refund_request_id', 'actor_id', 'event_type', 'from_status', 'to_status'];
    protected $casts = ['created_at' => 'datetime'];
}
