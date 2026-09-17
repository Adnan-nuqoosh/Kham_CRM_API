<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ProductVariant extends Model {
 protected $fillable=['product_id','sku','barcode','title','option_values','weight_grams','cost_price','is_active']; protected $casts=['option_values'=>'array','cost_price'=>'decimal:4','is_active'=>'boolean'];
 public function product():BelongsTo{return $this->belongsTo(Product::class);} public function prices():HasMany{return $this->hasMany(ProductMarketPrice::class,'variant_id');} public function stocks():HasMany{return $this->hasMany(InventoryStock::class,'variant_id');}
}
