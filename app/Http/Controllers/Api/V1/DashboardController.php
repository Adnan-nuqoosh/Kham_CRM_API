<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{Customer,InventoryStock,Order,Product};
use App\Support\ApiResponse;
class DashboardController extends Controller { public function __invoke(){ $today=now()->startOfDay();$data=['orders_today'=>Order::where('placed_at','>=',$today)->count(),'revenue_today'=>(float)Order::where('placed_at','>=',$today)->whereNotIn('status',['cancelled','returned'])->sum('grand_total'),'customers_total'=>Customer::count(),'active_products'=>Product::where('status','active')->count(),'low_stock_skus'=>InventoryStock::whereRaw('(on_hand - reserved - damaged) <= reorder_level')->count(),'orders_by_status'=>Order::selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total','status')];return ApiResponse::success($data,'Dashboard fetched.','DASHBOARD');} }
