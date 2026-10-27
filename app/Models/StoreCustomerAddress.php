<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StoreCustomerAddress extends Model {
 protected $fillable=['store_customer_id','label','first_name','last_name','phone','line1','line2','city','state','postal_code','country_code','is_default'];
 protected $casts=['is_default'=>'boolean'];
}
