<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Currency extends Model { protected $fillable=['code','name','symbol','decimal_places','rate_to_base','is_base','is_active']; protected $casts=['rate_to_base'=>'decimal:8','is_base'=>'boolean','is_active'=>'boolean']; }
