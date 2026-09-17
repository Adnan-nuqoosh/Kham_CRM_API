<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Coupon extends Model { protected $fillable=['code','name','type','value','min_spend','max_discount','starts_at','ends_at','max_uses','max_uses_per_customer','rules','is_active']; protected $casts=['value'=>'decimal:4','min_spend'=>'decimal:4','max_discount'=>'decimal:4','starts_at'=>'datetime','ends_at'=>'datetime','rules'=>'array','is_active'=>'boolean']; }
