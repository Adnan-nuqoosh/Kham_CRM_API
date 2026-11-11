<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MyFatoorahPaymentReconciler;
use App\Services\MyFatoorahSandboxSettlementService;
use App\Services\MyFatoorahV3Client;
use App\Services\MyFatoorahWebhookReceiptService;
use App\Services\MyFatoorahWebhookV2Verifier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Local/testing-only signed webhook integration harness.
 * It must NOT be used as a production payment callback.
 * An actual sandbox token and secure webhook key are required for real calls.
 */
final class LocalMyFatoorahWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        MyFatoorahWebhookV2Verifier $verifier,
        MyFatoorahV3Client $client,
        MyFatoorahPaymentReconciler $reconciler,
        MyFatoorahWebhookReceiptService $receipts,
        MyFatoorahSandboxSettlementService $settlement
    ): JsonResponse {
        if (!app()->environment('local', 'testing')
            || !config('commerce.myfatoorah_sandbox_enabled', false)
            || !config('commerce.myfatoorah_sandbox_webhook_enabled', false)) {
            abort(404);
        }
        if (strlen($request->getContent()) > 65536 || !$request->isJson()) {
            return ApiResponse::error('JSON webhook payload required (maximum 64 KiB).',
                'INVALID_WEBHOOK_BODY', 400);
        }

        // Never accept order IDs, amounts or paid status directly from the caller.
        $signed = $verifier->verify(
            $request->json()->all(),
            $request->header('MyFatoorah-Signature'),
            (string) config('commerce.myfatoorah_webhook_secret', '')
        );

        if (!preg_match('/^[0-9]{1,64}$/D', $signed['payment_id'])
            || !preg_match('/^[0-9]{1,64}$/D', $signed['invoice_id'])) {
            throw ValidationException::withMessages([
                'payment' => ['Invalid provider payment identifiers.'],
            ]);
        }

        // This payment attempt must have been stored by the server beforehand.
        // A signed event by itself must never create or select a new order.
        $attempts = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.provider', 'myfatoorah')
            ->where('payments.transaction_reference', $signed['invoice_id'])
            ->where('orders.order_number', $signed['external_identifier'])
            ->select('payments.id as attempt_id', 'orders.id as order_id',
                'orders.order_number', 'orders.grand_total',
                'orders.currency_code')
            ->limit(2)->get();
        if ($attempts->count() !== 1) {
            throw ValidationException::withMessages([
                'payment' => ['Matching stored payment attempt not found or ambiguous.'],
            ]);
        }
        $attempt = $attempts->first();

        // GET /v3/payments/{id} is authoritative; do not trust the webhook
        // or browser redirect alone to decide payment status.
        $provider = $client->getPaymentDetails($signed['payment_id']);
        $verified = $reconciler->verify(
            $signed, $provider, $signed['invoice_id'],
            (string) $attempt->order_number, (string) $attempt->grand_total,
            (string) $attempt->currency_code
        );

        if (!$verified['verified_paid']) {
            return ApiResponse::success(
                ['payment_status' => 'pending', 'order_payment_status_changed' => false],
                'Provider payment is not confirmed paid.', 'PAYMENT_NOT_CONFIRMED', 202
            )->header('Cache-Control', 'no-store');
        }

        // Receipt and paid transition commit/rollback together.
        $result = DB::transaction(function () use (
            $receipts, $settlement, $signed, $verified, $attempt
        ): array {
            $receipt = $receipts->record($signed, $verified);
            $applied = $settlement->apply((int) $attempt->order_id, $signed, $verified);
            DB::table('myfatoorah_webhook_receipts')
                ->where('id', $receipt['receipt_id'])
                ->update(['result' => 'applied', 'updated_at' => now()]);
            return [
                'payment_status' => 'paid',
                'order_payment_status_changed' => $applied['applied'],
                'duplicate' => $applied['duplicate'],
            ];
        }, 5);

        return ApiResponse::success(
            $result, 'Sandbox webhook payment verified.',
            'SANDBOX_PAYMENT_VERIFIED'
        )->header('Cache-Control', 'no-store');
    }
}
