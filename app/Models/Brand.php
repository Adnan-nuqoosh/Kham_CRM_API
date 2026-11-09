<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Brand extends Model { protected $fillable=['name','name_ar','description_ar','slug','origin_country','description','logo_url','is_active']; protected $casts=['is_active'=>'boolean']; }
