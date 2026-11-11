<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * CRM-only, local/testing-only read-only inspection of a hosted initiation.
 * No provider request, status mutation, payment creation or retry.
 */
final class LocalMyFatoorahReservationStatusController extends Controller
{
    public function __invoke(Order $order): JsonResponse
    {
        if (!app()->environment('local', 'testing')
            || !config('commerce.myfatoorah_sandbox_enabled', false)
            || !config('commerce.myfatoorah_sandbox_hosted_checkout_enabled', false)) {
            abort(404);
        }

        $reservation = DB::table('myfatoorah_checkout_reservations')
            ->where('order_id', $order->id)->first();

        if (!$reservation) {
            return ApiResponse::success([
                'order_number' => $order->order_number,
                'reservation_exists' => false,
                'payment_status' => $order->payment_status->value,
                'safe_to_retry' => false,
                'sandbox' => true,
            ], 'No hosted reservation found. This is not proof that the provider has no payment.',
                'SANDBOX_HOSTED_RESERVATION_NOT_FOUND', 200)->header('Cache-Control', 'no-store');
        }

        $payment = $reservation->invoice_id
            ? DB::table('payments')->where('provider', 'myfatoorah')
                ->where('order_id', $order->id)
                ->where('transaction_reference', $reservation->invoice_id)->first()
            : null;

        return ApiResponse::success([
            'order_number' => $order->order_number,
            'reservation_exists' => true,
            'initiation_status' => $reservation->status,
            'invoice_id' => $reservation->invoice_id,
            'payment_attempt_id' => $payment?->id,
            'payment_status' => $payment?->status ?? $order->payment_status->value,
            'provider_requested_at' => $reservation->provider_requested_at,
            'resolved_at' => $reservation->resolved_at,
            'requires_manual_reconciliation' => in_array(
                $reservation->status, ['reserved', 'requesting', 'uncertain'], true
            ),
            'safe_to_retry' => false,
            'sandbox' => true,
        ], 'Read-only reservation status; no provider payment verification performed.',
            'SANDBOX_HOSTED_RESERVATION_STATUS', 200)->header('Cache-Control', 'no-store');
    }
}
