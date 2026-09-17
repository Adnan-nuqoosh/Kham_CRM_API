<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InventoryStock extends Model { protected $fillable=['variant_id','warehouse_id','on_hand','reserved','damaged','reorder_level']; protected $appends=['available']; public function getAvailableAttribute():int{return max(0,$this->on_hand-$this->reserved-$this->damaged);} public function variant():BelongsTo{return $this->belongsTo(ProductVariant::class,'variant_id');} public function warehouse():BelongsTo{return $this->belongsTo(Warehouse::class);} }
