<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StoreMarketRule extends Model {
 public function market(){return $this->belongsTo(Market::class);}
 protected $fillable=['market_id','shipping_flat','shipping_free_threshold','tax_percent','tax_inclusive','cod_enabled','is_active'];
 protected $casts=['shipping_flat'=>'decimal:4','shipping_free_threshold'=>'decimal:4','tax_percent'=>'decimal:4','tax_inclusive'=>'boolean','cod_enabled'=>'boolean','is_active'=>'boolean'];
}
