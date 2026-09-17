<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ProductMarketPrice extends Model { protected $fillable=['variant_id','market_id','currency_id','price','compare_at_price','starts_at','ends_at','is_active']; protected $casts=['price'=>'decimal:4','compare_at_price'=>'decimal:4','starts_at'=>'datetime','ends_at'=>'datetime','is_active'=>'boolean']; public function variant():BelongsTo{return $this->belongsTo(ProductVariant::class,'variant_id');} public function market():BelongsTo{return $this->belongsTo(Market::class);} public function currency():BelongsTo{return $this->belongsTo(Currency::class);} }
