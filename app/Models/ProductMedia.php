<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProductMedia extends Model { protected $fillable=['product_id','type','url','alt_text','sort_order']; }
