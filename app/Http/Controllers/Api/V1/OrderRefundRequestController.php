<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderRefundRequest;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderRefundRequestController extends Controller
{
    public function index(Order $order)
    {
        return ApiResponse::success(OrderRefundRequest::where('order_id', $order->id)->latest('id')->get(),
            'Refund requests fetched.', 'REFUND_REQUEST_LIST');
    }

    public function store(Request $request, Order $order)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $refund = DB::transaction(function () use ($order, $request, $data) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== PaymentStatus::Paid) {
                throw ValidationException::withMessages(['payment' => ['Only fully paid orders can request a refund.']]);
            }
            if (OrderRefundRequest::where('order_id', $locked->id)
                ->whereIn('status', ['pending_approval', 'approved_awaiting_provider'])->exists()) {
                throw ValidationException::withMessages(['refund' => ['An open refund request already exists.']]);
            }
            $payments = $locked->payments()->where('status', PaymentStatus::Paid->value)->lockForUpdate()->get();
            if ($payments->count() !== 1
                || (string) $payments->first()->amount !== (string) $locked->grand_total
                || (string) $payments->first()->currency_code !== (string) $locked->currency_code) {
                throw ValidationException::withMessages(['payment' => ['Exactly one matching settled payment is required.']]);
            }
            return OrderRefundRequest::create([
                'order_id' => $locked->id, 'payment_id' => $payments->first()->id,
                'amount' => $locked->grand_total, 'currency_code' => $locked->currency_code,
                'reason' => $data['reason'], 'requested_by' => $request->user()->id,
            ]);
        }, 5);
        return ApiResponse::success($refund, 'Refund requested; payment unchanged and no provider refund initiated.',
            'REFUND_REQUEST_CREATED', 201);
    }

    public function approve(Request $request, OrderRefundRequest $refund)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        $approved = DB::transaction(function () use ($refund, $request) {
            $locked = OrderRefundRequest::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending_approval') {
                throw ValidationException::withMessages(['refund' => ['Only pending requests can be approved.']]);
            }
            if ((int) $locked->requested_by === (int) $request->user()->id) {
                throw ValidationException::withMessages(['refund' => ['Requester cannot approve their own refund.']]);
            }
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $payment = $order->payments()->whereKey($locked->payment_id)->lockForUpdate()->first();
            if (!$payment || $order->payment_status !== PaymentStatus::Paid
                || $payment->status !== PaymentStatus::Paid->value
                || (string) $payment->amount !== (string) $locked->amount
                || (string) $payment->currency_code !== (string) $locked->currency_code) {
                throw ValidationException::withMessages(['payment' => ['Payment no longer matches the refund request.']]);
            }
            $locked->forceFill([
                'status' => 'approved_awaiting_provider',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();
            return $locked->fresh();
        }, 5);
        return ApiResponse::success($approved,
            'Finance approved. Provider refund is not initiated or confirmed; payment remains paid.',
            'REFUND_APPROVED_AWAITING_PROVIDER');
    }
}
