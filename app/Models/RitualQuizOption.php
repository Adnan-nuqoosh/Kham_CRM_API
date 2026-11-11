<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RitualQuizOption extends Model
{
    protected $fillable = ['question_id', 'label', 'label_ar', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(RitualQuizQuestion::class, 'question_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ritual_quiz_option_product', 'option_id', 'product_id')
            ->withPivot('weight');
    }
}
