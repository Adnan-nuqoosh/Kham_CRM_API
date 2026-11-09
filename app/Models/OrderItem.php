<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderItem extends Model { public function order():\Illuminate\Database\Eloquent\Relations\BelongsTo{return $this->belongsTo(Order::class);}  protected $fillable=['order_id','product_id','variant_id','sku','name','quantity','unit_price','discount_total','tax_total','line_total','unit_cost_snapshot','unit_cost_currency_code']; protected $casts=['unit_price'=>'decimal:4','discount_total'=>'decimal:4','tax_total'=>'decimal:4','line_total'=>'decimal:4','unit_cost_snapshot'=>'decimal:4']; }
