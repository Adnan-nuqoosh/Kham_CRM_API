<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ritual extends Model
{
    protected $fillable = ['slug','title','title_ar','description','description_ar','image_url','is_published','sort_order'];
    protected $casts = ['is_published'=>'boolean'];
    public function steps(): HasMany
    {
        return $this->hasMany(RitualStep::class)->orderBy('sort_order')->orderBy('id');
    }
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ritual_product')->withPivot('sort_order')->orderByPivot('sort_order');
    }
}
