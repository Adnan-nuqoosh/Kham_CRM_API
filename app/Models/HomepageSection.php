<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class HomepageSection extends Model {
 protected $fillable=['key','type','title_en','title_ar','subtitle_en','subtitle_ar',
  'image_url','link_url','content','sort_order','is_published'];
 protected $casts=['content'=>'array','is_published'=>'boolean','sort_order'=>'integer'];
}
