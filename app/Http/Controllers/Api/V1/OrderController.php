<?php
namespace App\Http\Controllers\Api\V1;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderStoreRequest;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OrderController extends Controller {
 public function index(Request $r){$q=Order::with(['customer','market.currency','warehouse'])->withCount('items');foreach(['status','payment_status','market_id','warehouse_id'] as $f)if($r->filled($f))$q->where($f,$r->$f);if($r->filled('search'))$q->where('order_number','like','%'.$r->search.'%');$p=$q->latest('id')->paginate(PaginationMeta::perPage($r,25));return ApiResponse::success($p->items(),'Orders fetched.','ORDER_LIST',200,PaginationMeta::from($p));}
 public function store(OrderStoreRequest $r,OrderService $service){return ApiResponse::success($service->create($r->validated()),'Order created and inventory reserved.','ORDER_CREATED',201);}
 public function show(Order $order){return ApiResponse::success($order->load(['customer','market.currency','warehouse','items','addresses','payments','shipments']));}
 public function status(Request $r,Order $order,\App\Services\OrderStatusService $service){
  $d=$r->validate(['status'=>'required|in:pending,confirmed,processing,packed,shipped,delivered,cancelled,returned']);
  $next=OrderStatus::from($d['status']);
  return ApiResponse::success($service->transition($order,$next),'Order status updated.','ORDER_STATUS_UPDATED');
 }
}
