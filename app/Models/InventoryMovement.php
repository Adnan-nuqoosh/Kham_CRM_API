<?php
namespace App\Models;
use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
class InventoryMovement extends Model { public $timestamps=false; protected $fillable=['variant_id','warehouse_id','type','quantity','reference_type','reference_id','note','created_by','created_at']; protected $casts=['type'=>MovementType::class,'created_at'=>'datetime']; }
