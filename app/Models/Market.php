<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Market extends Model { protected $fillable=['name','code','country_code','currency_id','timezone','is_default','is_active']; protected $casts=['is_default'=>'boolean','is_active'=>'boolean']; public function currency():BelongsTo{return $this->belongsTo(Currency::class);} }
