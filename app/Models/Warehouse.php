<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Warehouse extends Model { protected $fillable=['market_id','name','code','address','is_active']; protected $casts=['address'=>'array','is_active'=>'boolean']; public function market():BelongsTo{return $this->belongsTo(Market::class);} }
