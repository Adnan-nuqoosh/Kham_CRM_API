<?php
namespace App\Http\Controllers\Api\V1;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderStoreRequest;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OrderController extends Controller {
 public function index(Request $r){$q=Order::with(['customer','market.currency','warehouse'])->withCount('items');foreach(['status','payment_status','market_id','warehouse_id'] as $f)if($r->filled($f))$q->where($f,$r->$f);if($r->filled('search'))$q->where('order_number','like','%'.$r->search.'%');$p=$q->latest('id')->paginate(min((int)$r->get('per_page',25),100));return ApiResponse::success($p->items(),'Orders fetched.','ORDER_LIST',200,['pagination'=>['total'=>$p->total(),'current_page'=>$p->currentPage(),'last_page'=>$p->lastPage()]]);}
 public function store(OrderStoreRequest $r,OrderService $service){return ApiResponse::success($service->create($r->validated()),'Order created and inventory reserved.','ORDER_CREATED',201);}
 public function show(Order $order){return ApiResponse::success($order->load(['customer','market.currency','warehouse','items','addresses','payments','shipments']));}
 public function status(Request $r,Order $order,InventoryService $inventory){$d=$r->validate(['status'=>'required|in:pending,confirmed,processing,packed,shipped,delivered,cancelled,returned']);$next=OrderStatus::from($d['status']);DB::transaction(function()use($order,$next,$inventory){if($next===OrderStatus::Cancelled&&!in_array($order->status,[OrderStatus::Cancelled,OrderStatus::Delivered]))foreach($order->items as $item)$inventory->release($item->variant_id,$order->warehouse_id,$item->quantity,$order->id);if($next===OrderStatus::Shipped&&$order->status!==OrderStatus::Shipped)foreach($order->items as $item)$inventory->commitSale($item->variant_id,$order->warehouse_id,$item->quantity,$order->id);$order->update(['status'=>$next,'fulfillment_status'=>match($next){OrderStatus::Packed=>'packed',OrderStatus::Shipped=>'shipped',OrderStatus::Delivered=>'delivered',OrderStatus::Cancelled=>'cancelled',default=>$order->fulfillment_status}]);});return ApiResponse::success($order->fresh(['items']),'Order status updated.','ORDER_STATUS_UPDATED');}
}
