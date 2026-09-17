<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Customer extends Model { protected $fillable=['first_name','last_name','email','phone','status','tags','notes','marketing_opt_in']; protected $casts=['tags'=>'array','marketing_opt_in'=>'boolean']; protected $appends=['full_name']; public function getFullNameAttribute():string{return trim($this->first_name.' '.$this->last_name);} public function addresses():HasMany{return $this->hasMany(CustomerAddress::class);} public function orders():HasMany{return $this->hasMany(Order::class);} }
