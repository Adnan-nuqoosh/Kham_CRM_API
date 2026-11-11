<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RitualQuizQuestion extends Model
{
    protected $fillable = ['question', 'question_ar', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function options(): HasMany
    {
        return $this->hasMany(RitualQuizOption::class, 'question_id')->orderBy('sort_order')->orderBy('id');
    }
}
