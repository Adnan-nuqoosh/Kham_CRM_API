<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Payment extends Model { protected $fillable=['order_id','provider','method','transaction_reference','status','amount','currency_code','payload','paid_at']; protected $casts=['amount'=>'decimal:4','payload'=>'array','paid_at'=>'datetime']; }
