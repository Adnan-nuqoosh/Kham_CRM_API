<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shipment extends Model { protected $fillable=['order_id','carrier','service','tracking_number','status','shipping_cost','label_url','shipped_at','delivered_at','metadata']; protected $casts=['shipping_cost'=>'decimal:4','shipped_at'=>'datetime','delivered_at'=>'datetime','metadata'=>'array']; }
