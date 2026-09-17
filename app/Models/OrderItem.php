<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderItem extends Model { protected $fillable=['order_id','product_id','variant_id','sku','name','quantity','unit_price','discount_total','tax_total','line_total']; protected $casts=['unit_price'=>'decimal:4','discount_total'=>'decimal:4','tax_total'=>'decimal:4','line_total'=>'decimal:4']; }
