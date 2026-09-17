<?php
namespace App\Models;
use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Product extends Model {
 protected $fillable=['brand_id','category_id','name','slug','short_description','description','ingredients','usage_instructions','status','track_inventory','seo_title','seo_description','metadata'];
 protected $casts=['status'=>ProductStatus::class,'track_inventory'=>'boolean','metadata'=>'array'];
 public function brand():BelongsTo{return $this->belongsTo(Brand::class);} public function category():BelongsTo{return $this->belongsTo(Category::class);} public function variants():HasMany{return $this->hasMany(ProductVariant::class);} public function media():HasMany{return $this->hasMany(ProductMedia::class)->orderBy('sort_order');}
}
