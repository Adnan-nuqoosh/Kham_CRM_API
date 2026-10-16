<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceExpense extends Model
{
    use SoftDeletes;
    protected $fillable = ['market_id', 'currency_code', 'category', 'description', 'amount', 'incurred_on', 'reference', 'created_by'];
    protected $casts = ['amount' => 'decimal:4', 'incurred_on' => 'date'];
}
