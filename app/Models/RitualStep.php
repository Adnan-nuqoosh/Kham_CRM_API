<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RitualStep extends Model
{
    protected $fillable = ['title','title_ar','instructions','instructions_ar','sort_order'];
    public function ritual(): BelongsTo
    {
        return $this->belongsTo(Ritual::class);
    }
}
