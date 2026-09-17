<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerAddress extends Model { protected $fillable=['customer_id','type','first_name','last_name','phone','line1','line2','city','state','postal_code','country_code','is_default']; protected $casts=['is_default'=>'boolean']; }
