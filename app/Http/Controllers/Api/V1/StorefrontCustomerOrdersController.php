<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\StorefrontAccount;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;

final class StorefrontCustomerOrdersController extends Controller
{
    private function customerId(Request $request): int
    {
        $account=$request->user();
        if (!$account instanceof StorefrontAccount || !$account->tokenCan('storefront')) abort(403);
        return $account->customer_id;
    }

    public function index(Request $request)
    {
        $customerId=$this->customerId($request);
        $orders=Order::query()->where('customer_id',$customerId)
            ->withCount('items')->latest('id')
            ->paginate(PaginationMeta::perPage($request,15,50));
        return ApiResponse::success($orders->items(),'Customer orders fetched.','STOREFRONT_ORDERS',
            200,PaginationMeta::from($orders))->header('Cache-Control','no-store');
    }

    public function show(Request $request,string $orderNumber)
    {
        $customerId=$this->customerId($request);
        $order=Order::query()->where('customer_id',$customerId)
            ->where('order_number',$orderNumber)
            ->with(['items','addresses','shipments'])->firstOrFail();
        return ApiResponse::success([
            'order_number'=>$order->order_number,
            'status'=>$order->status,
            'payment_status'=>$order->payment_status,
            'fulfillment_status'=>$order->fulfillment_status,
            'market_id'=>$order->market_id,
            'currency_code'=>$order->currency_code,
            'subtotal'=>$order->subtotal,
            'discount_total'=>$order->discount_total,
            'shipping_total'=>$order->shipping_total,
            'tax_total'=>$order->tax_total,
            'grand_total'=>$order->grand_total,
            'placed_at'=>$order->placed_at,
            'items'=>$order->items,
            'shipping_address'=>$order->addresses->firstWhere('type','shipping'),
            'shipments'=>$order->shipments->map(fn ($shipment)=>[
                'carrier'=>$shipment->carrier,
                'tracking_number'=>$shipment->tracking_number,
                'status'=>$shipment->status,
                'shipped_at'=>$shipment->shipped_at,
                'delivered_at'=>$shipment->delivered_at,
            ])->values()->all(),
        ],'Customer order fetched.','STOREFRONT_ORDER')->header('Cache-Control','no-store');
    }
}
