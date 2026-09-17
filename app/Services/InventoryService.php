<?php
namespace App\Services;
use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class InventoryService {
 public function reserve(int $variantId,int $warehouseId,int $quantity,?int $referenceId=null):InventoryStock {
  return DB::transaction(function()use($variantId,$warehouseId,$quantity,$referenceId){
   $stock=InventoryStock::query()->where('variant_id',$variantId)->where('warehouse_id',$warehouseId)->lockForUpdate()->firstOrFail();
   if($quantity<1||$stock->available<$quantity) throw ValidationException::withMessages(['quantity'=>["Insufficient stock. Available: {$stock->available}."]]);
   $stock->increment('reserved',$quantity); $stock->refresh();
   InventoryMovement::create(['variant_id'=>$variantId,'warehouse_id'=>$warehouseId,'type'=>MovementType::Reservation,'quantity'=>-$quantity,'reference_type'=>'order','reference_id'=>$referenceId,'note'=>'Stock reserved','created_by'=>auth()->id(),'created_at'=>now()]);
   return $stock;
  });
 }
 public function release(int $variantId,int $warehouseId,int $quantity,?int $referenceId=null):void { DB::transaction(function()use($variantId,$warehouseId,$quantity,$referenceId){$stock=InventoryStock::query()->where('variant_id',$variantId)->where('warehouse_id',$warehouseId)->lockForUpdate()->firstOrFail();$stock->update(['reserved'=>max(0,$stock->reserved-$quantity)]);InventoryMovement::create(['variant_id'=>$variantId,'warehouse_id'=>$warehouseId,'type'=>MovementType::Release,'quantity'=>$quantity,'reference_type'=>'order','reference_id'=>$referenceId,'note'=>'Reservation released','created_by'=>auth()->id(),'created_at'=>now()]);}); }
 public function commitSale(int $variantId,int $warehouseId,int $quantity,?int $referenceId=null):void { DB::transaction(function()use($variantId,$warehouseId,$quantity,$referenceId){$stock=InventoryStock::query()->where('variant_id',$variantId)->where('warehouse_id',$warehouseId)->lockForUpdate()->firstOrFail();if($stock->on_hand<$quantity)throw ValidationException::withMessages(['quantity'=>['Insufficient on-hand stock.']]);$stock->update(['on_hand'=>$stock->on_hand-$quantity,'reserved'=>max(0,$stock->reserved-$quantity)]);InventoryMovement::create(['variant_id'=>$variantId,'warehouse_id'=>$warehouseId,'type'=>MovementType::Sale,'quantity'=>-$quantity,'reference_type'=>'order','reference_id'=>$referenceId,'note'=>'Stock sold','created_by'=>auth()->id(),'created_at'=>now()]);}); }
}
