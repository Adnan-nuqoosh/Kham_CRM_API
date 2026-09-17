<?php
namespace App\Http\Controllers\Api\V1;
use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryAdjustRequest;
use App\Models\{InventoryMovement,InventoryStock};
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class InventoryController extends Controller {
 public function index(Request $r){$q=InventoryStock::with(['variant.product','warehouse.market']);if($r->filled('warehouse_id'))$q->where('warehouse_id',$r->warehouse_id);if($r->boolean('low_stock'))$q->whereRaw('(on_hand - reserved - damaged) <= reorder_level');$p=$q->paginate(min((int)$r->get('per_page',50),100));return ApiResponse::success($p->items(),'Inventory fetched.','INVENTORY_LIST',200,['pagination'=>['total'=>$p->total(),'current_page'=>$p->currentPage(),'last_page'=>$p->lastPage()]]);}
 public function adjust(InventoryAdjustRequest $r){$stock=DB::transaction(function()use($r){$s=InventoryStock::firstOrCreate(['variant_id'=>$r->variant_id,'warehouse_id'=>$r->warehouse_id],['on_hand'=>0,'reserved'=>0,'damaged'=>0,'reorder_level'=>10]);$s=InventoryStock::whereKey($s->id)->lockForUpdate()->first();$new=$s->on_hand+$r->quantity_delta;if($new<0)throw ValidationException::withMessages(['quantity_delta'=>['Adjustment would make on-hand stock negative.']]);$s->update(['on_hand'=>$new]);InventoryMovement::create(['variant_id'=>$r->variant_id,'warehouse_id'=>$r->warehouse_id,'type'=>MovementType::Adjustment,'quantity'=>$r->quantity_delta,'note'=>$r->reason,'created_by'=>auth()->id(),'created_at'=>now()]);return $s->fresh(['variant.product','warehouse']);});return ApiResponse::success($stock,'Inventory adjusted.','INVENTORY_ADJUSTED');}
 public function movements(Request $r){$q=InventoryMovement::query()->latest('created_at');foreach(['variant_id','warehouse_id','type'] as $f)if($r->filled($f))$q->where($f,$r->$f);return ApiResponse::success($q->paginate(min((int)$r->get('per_page',50),100)));}
}
