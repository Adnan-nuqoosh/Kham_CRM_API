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

        $keyHash = hash('sha256', $data['idempotency_key']);
        $reservation = DB::transaction(function () use ($order, $data, $keyHash): array {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->payment_status !== PaymentStatus::Pending
                || in_array($locked->status->value, ['cancelled', 'returned'], true)
                || (float) $locked->grand_total <= 0) {
                throw ValidationException::withMessages([
                    'payment' => ['Order is not eligible for sandbox hosted payment.'],
                ]);
            }

            $existing = DB::table('myfatoorah_checkout_reservations')
                ->where('order_id', $locked->id)->first();
            if ($existing) {
                if (!hash_equals((string) $existing->idempotency_key_hash, $keyHash)
                    || (string) ($existing->payment_method ?? '') !== (string) ($data['payment_method'] ?? '')) {
                    throw ValidationException::withMessages([
                        'payment' => ['Order already has a different hosted payment initiation. Reconcile it before retrying.'],
                    ]);
                }
                return ['reservation_id' => $existing->id, 'existing' => true,
                    'order_number' => $locked->order_number];
            }
            if ($locked->payments()->exists()) {
                throw ValidationException::withMessages([
                    'payment' => ['Order already has a payment attempt.'],
                ]);
            }
            $id = DB::table('myfatoorah_checkout_reservations')->insertGetId([
                'order_id' => $locked->id, 'idempotency_key_hash' => $keyHash,
                'status' => 'reserved', 'amount' => $locked->grand_total,
                'currency_code' => $locked->currency_code,
                'payment_method' => $data['payment_method'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['reservation_id' => $id, 'existing' => false,
                'order_number' => $locked->order_number];
        }, 3);

        if ($reservation['existing']) {
            $stored = DB::table('myfatoorah_checkout_reservations')
                ->where('id', $reservation['reservation_id'])->first();
            if ($stored->status === 'initiated' && $stored->invoice_id && $stored->payment_url) {
                $payment = DB::table('payments')->where('provider', 'myfatoorah')
                    ->where('transaction_reference', $stored->invoice_id)
                    ->where('order_id', $order->id)->first();
                if ($payment) {
                    return ApiResponse::success([
                        'order_number' => $reservation['order_number'],
                        'payment_attempt_id' => $payment->id,
                        'invoice_id' => $stored->invoice_id,
                        'payment_url' => $stored->payment_url,
                        'payment_status' => $payment->status, 'sandbox' => true,
                    ], 'Existing sandbox hosted payment returned; no new provider call.',
                        'SANDBOX_HOSTED_PAYMENT_REUSED', 200)->header('Cache-Control', 'no-store');
                }
            }
            return ApiResponse::success([
                'order_number' => $reservation['order_number'],
                'initiation_status' => $stored->status,
                'payment_status' => 'pending', 'sandbox' => true,
            ], 'Previous provider initiation is unresolved. Do not start another payment; reconcile manually.',
                'SANDBOX_HOSTED_PAYMENT_UNRESOLVED', 202)->header('Cache-Control', 'no-store');
        }

        // Commit reservation before the network call. Never hold an order lock
        // while waiting for MyFatoorah.
        DB::table('myfatoorah_checkout_reservations')
            ->where('id', $reservation['reservation_id'])
            ->update(['status' => 'requesting', 'provider_requested_at' => now(), 'updated_at' => now()]);
        try {
            $response = $client->createHostedPayment(
                (string) $order->grand_total,
                $redirect,
                $data['idempotency_key'],
                $data['payment_method'] ?? null
            );
        } catch (\Throwable $e) {
            DB::table('myfatoorah_checkout_reservations')
                ->where('id', $reservation['reservation_id'])
                ->update(['status' => 'uncertain', 'updated_at' => now()]);
            throw $e;
        }

        try {
            $result = DB::transaction(function () use ($order, $response, $keyHash, $reservation): array {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
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
                    'idempotency_key_hash' => $keyHash,
                    'payment_url' => $response['payment_url'],
                ],
            ]);

            DB::table('myfatoorah_checkout_reservations')
                ->where('id', $reservation['reservation_id'])->update([
                    'status' => 'initiated', 'invoice_id' => $response['invoice_id'],
                    'payment_url' => $response['payment_url'],
                    'resolved_at' => now(), 'updated_at' => now(),
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
        } catch (\Throwable $e) {
            DB::table('myfatoorah_checkout_reservations')
                ->where('id', $reservation['reservation_id'])
                ->update(['status' => 'uncertain', 'updated_at' => now()]);
            throw $e;
        }

        return ApiResponse::success(
            $result,
            'Sandbox hosted payment initiated. Redirect is not proof of payment.',
            'SANDBOX_HOSTED_PAYMENT_CREATED',
            201
        )->header('Cache-Control', 'no-store');
    }
}
