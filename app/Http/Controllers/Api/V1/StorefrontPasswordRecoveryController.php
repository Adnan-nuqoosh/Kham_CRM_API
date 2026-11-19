<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StorefrontAccount;
use App\Notifications\StorefrontPasswordResetNotification;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class StorefrontPasswordRecoveryController extends Controller
{
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $frontend = trim((string) config('commerce.storefront_password_reset_frontend_url', ''));
        if (!filter_var($frontend, FILTER_VALIDATE_URL)
            || parse_url($frontend, PHP_URL_SCHEME) !== 'https') {
            return ApiResponse::error('Storefront password recovery is not configured.', 'STOREFRONT_RESET_UNAVAILABLE', 503);
        }
        Password::broker('storefront_accounts')->sendResetLink($data,
            function (StorefrontAccount $account, string $token) use ($frontend) {
                $account->notify(new StorefrontPasswordResetNotification($token, $frontend));
            });
        return ApiResponse::success(
            null,
            'If this email is registered, a password reset link will be sent.',
            'STOREFRONT_RESET_REQUESTED'
        )->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        $status = Password::broker('storefront_accounts')->reset(
            $data,
            function (StorefrontAccount $account, string $password) {
                $account->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $account->tokens()->delete();
                event(new PasswordReset($account));
            }
        );
        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                'The reset token is invalid or expired.',
                'STOREFRONT_RESET_INVALID',
                422
            )->header('Cache-Control', 'no-store');
        }
        return ApiResponse::success(
            null,
            'Storefront password reset successfully. Please log in again.',
            'STOREFRONT_RESET_COMPLETE'
        )->header('Cache-Control', 'no-store');
    }
}
