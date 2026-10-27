<?php
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
class StoreCustomer extends Authenticatable {
 use HasApiTokens,Notifiable;
 protected $fillable=['name','email','password','marketing_opt_in'];
 protected $hidden=['password','remember_token'];
 protected $casts=['password'=>'hashed','marketing_opt_in'=>'boolean','email_verified_at'=>'datetime'];
 public function addresses(){return $this->hasMany(StoreCustomerAddress::class);}
 public function wishlist(){return $this->belongsToMany(Product::class,'store_wishlists')->withTimestamps();}
 public function orders(){return $this->hasMany(Order::class,'store_customer_id');}
}
