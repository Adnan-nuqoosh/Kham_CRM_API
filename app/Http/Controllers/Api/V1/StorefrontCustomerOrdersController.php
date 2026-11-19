<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\StorefrontAccount;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $reservation=DB::table('myfatoorah_checkout_reservations')
            ->where('order_id',$order->id)->first(['status','payment_url']);
        $pending=$order->payment_status->value==='pending';
        $initiated=$pending && $reservation && $reservation->status==='initiated';
        $url=$initiated ? (string)($reservation->payment_url??'') : '';
        $resumable=$url!=='' && filter_var($url,FILTER_VALIDATE_URL)
            && parse_url($url,PHP_URL_SCHEME)==='https';
        // A checkout error or lost redirect must never cause a fresh payment
        // initiation for an existing order. Only verified provider settlement
        // changes payment status. Keep unknown sessions pending for operator review.
        $paymentRecovery=[
            'payment_status'=>$order->payment_status->value,
            'initiation_status'=>$reservation?->status,
            'can_resume_payment'=>(bool)$resumable,
            'payment_url'=>$resumable ? $url : null,
            'requires_reconciliation'=>$pending && $reservation
                && in_array($reservation->status,['reserved','requesting','uncertain'],true),
        ];

        return ApiResponse::success([
            'payment_recovery'=>$paymentRecovery,
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
