<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MyFatoorahV3Client;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only operator investigation. A provider enquiry alone cannot settle an
 * order and is not a substitute for a signed webhook or verified settlement.
 */
final class LocalMyFatoorahProviderEnquiryController extends Controller
{
    public function __invoke(Request $request, Order $order, MyFatoorahV3Client $client): JsonResponse
    {
        if (!app()->environment('local', 'testing')
            || !config('commerce.myfatoorah_sandbox_enabled', false)
            || !config('commerce.myfatoorah_sandbox_hosted_checkout_enabled', false)) {
            abort(404);
        }

        $data = $request->validate([
            'payment_id' => ['required', 'regex:/^[0-9]{1,64}$/D'],
        ]);

        $reservation = DB::table('myfatoorah_checkout_reservations')
            ->where('order_id', $order->id)->first();

        // Do not let an operator-supplied invoice ID establish ownership.
        if (!$reservation || !is_string($reservation->invoice_id)
            || !preg_match('/^[0-9]{1,64}$/D', $reservation->invoice_id)) {
            throw ValidationException::withMessages([
                'payment' => ['No locally persisted invoice reference. Manual investigation required; no provider enquiry performed.'],
            ]);
        }

        $details = $client->getPaymentDetails($data['payment_id']);
        $invoice = (string) data_get($details, 'Invoice.Id', '');
        $payment = (string) data_get($details, 'Transaction.PaymentId', '');
        $external = (string) data_get($details, 'Invoice.ExternalIdentifier', '');
        $currency = (string) data_get($details, 'Amount.DisplayCurrency', '');
        $amount = data_get($details, 'Amount.ValueInDisplayCurrency');

        $expectedAmount = (string) $reservation->amount;
        $actualAmount = is_string($amount) || is_int($amount) ? (string) $amount : '';
        if (!hash_equals($reservation->invoice_id, $invoice)
            || !hash_equals((string) $data['payment_id'], $payment)
            || !hash_equals((string) $order->order_number, $external)
            || !hash_equals((string) $reservation->currency_code, $currency)
            || !preg_match('/^(0|[1-9][0-9]{0,10})(?:\.[0-9]{1,4})?$/D', $actualAmount)
            || bccomp($expectedAmount, $actualAmount, 4) !== 0
            || bccomp((string) $order->grand_total, $expectedAmount, 4) !== 0
            || (string) $order->currency_code !== (string) $reservation->currency_code) {
            throw ValidationException::withMessages([
                'payment' => ['Provider payment details do not match the stored invoice, order reference, amount or currency. No state changed.'],
            ]);
        }

        $invoiceStatus = (string) data_get($details, 'Invoice.Status', '');
        $transactionStatus = (string) data_get($details, 'Transaction.Status', '');

        return ApiResponse::success([
            'order_number' => $order->order_number,
            'invoice_id' => $invoice,
            'payment_id' => $payment,
            'provider_invoice_status' => $invoiceStatus,
            'provider_transaction_status' => $transactionStatus,
            'provider_reports_paid' => $invoiceStatus === 'PAID' && $transactionStatus === 'SUCCESS',
            'reconciliation_status' => 'manual_review_required',
            'payment_settled' => false,
            'safe_to_retry' => false,
            'sandbox' => true,
        ], 'Provider details match local references. Read-only investigation; no settlement or retry authorized.',
            'SANDBOX_PROVIDER_ENQUIRY_MATCHED', 200)->header('Cache-Control', 'no-store');
    }
}
