<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderAddress extends Model { protected $fillable=['order_id','type','first_name','last_name','phone','email','line1','line2','city','state','postal_code','country_code']; }
