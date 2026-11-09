<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MyFatoorahV3Client;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authenticated CRM-only sandbox hosted payment initiation.
 * Disabled by default; never usable outside local/testing.
 */
final class LocalMyFatoorahHostedCheckoutController extends Controller
{
    public function __invoke(Request $request, Order $order, MyFatoorahV3Client $client): JsonResponse
    {
        if (!app()->environment('local', 'testing')
            || !config('commerce.myfatoorah_sandbox_enabled', false)
            || !config('commerce.myfatoorah_sandbox_hosted_checkout_enabled', false)) {
            abort(404);
        }

        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:16', 'max:128',
                'regex:/^[A-Za-z0-9_-]+$/D'],
            'payment_method' => ['sometimes', 'in:CARD,APPLE_PAY,GOOGLE_PAY,KNET'],
            'amount' => ['prohibited'],
            'currency_code' => ['prohibited'],
            'redirect_url' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'invoice_id' => ['prohibited'],
        ]);

        $redirect = (string) config('commerce.myfatoorah_sandbox_redirect_url', '');
        if (!filter_var($redirect, FILTER_VALIDATE_URL)
            || parse_url($redirect, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages([
                'payment' => ['A server-configured HTTPS sandbox redirect URL is required.'],
            ]);
        }

        $result = DB::transaction(function () use ($order, $client, $data, $redirect): array {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== PaymentStatus::Pending
                || in_array($locked->status->value, ['cancelled', 'returned'], true)
                || (float) $locked->grand_total <= 0) {
                throw ValidationException::withMessages([
                    'payment' => ['Order is not eligible for sandbox hosted payment.'],
                ]);
            }

            // Refuse an existing payment attempt rather than creating a second charge.
            // In particular, a mock_fatoora order cannot be converted into a real attempt.
            if ($locked->payments()->exists()) {
                throw ValidationException::withMessages([
                    'payment' => ['Order already has a payment attempt.'],
                ]);
            }

            $response = $client->createHostedPayment(
                (string) $locked->grand_total,
                $redirect,
                $data['idempotency_key'],
                $data['payment_method'] ?? null
            );

            // Fail closed if the provider invoice is already linked to another attempt.
            // A database uniqueness constraint is still required for cross-order races.
            if (DB::table('payments')->where('provider', 'myfatoorah')
                ->where('transaction_reference', $response['invoice_id'])->exists()) {
                throw ValidationException::withMessages([
                    'payment' => ['Provider invoice is already linked to a payment attempt.'],
                ]);
            }

            $payment = $locked->payments()->create([
                'provider' => 'myfatoorah',
                'method' => 'hosted',
                'transaction_reference' => $response['invoice_id'],
                'status' => PaymentStatus::Pending->value,
                'amount' => $locked->grand_total,
                'currency_code' => $locked->currency_code,
                'payload' => [
                    'sandbox' => true,
                    'idempotency_key_hash' => hash('sha256', $data['idempotency_key']),
                    'payment_url' => $response['payment_url'],
                ],
            ]);

            return [
                'order_number' => $locked->order_number,
                'payment_attempt_id' => $payment->id,
                'invoice_id' => $response['invoice_id'],
                'payment_url' => $response['payment_url'],
                'payment_status' => 'pending',
                'sandbox' => true,
            ];
        }, 3);

        return ApiResponse::success(
            $result,
            'Sandbox hosted payment initiated. Redirect is not proof of payment.',
            'SANDBOX_HOSTED_PAYMENT_CREATED',
            201
        )->header('Cache-Control', 'no-store');
    }
}
