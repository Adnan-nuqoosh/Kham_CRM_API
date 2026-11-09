<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordRecoveryController extends Controller
{
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $frontend = trim((string) config('commerce.password_reset_frontend_url', ''));
        if (!filter_var($frontend, FILTER_VALIDATE_URL)
            || parse_url($frontend, PHP_URL_SCHEME) !== 'https') {
            return ApiResponse::error('Password recovery is not configured.', 'AUTH_RESET_UNAVAILABLE', 503);
        }

        // Do not disclose whether an email belongs to a CRM account.
        Password::broker()->sendResetLink($data, function (User $user, string $token) use ($frontend) {
            $user->notify(new \App\Notifications\CrmPasswordResetNotification($token, $frontend));
        });
        return ApiResponse::success(null, 'If this email is registered, a password reset link will be sent.', 'AUTH_RESET_REQUESTED');
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        $status = Password::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('The reset token is invalid or expired.', 'AUTH_RESET_INVALID', 422);
        }
        return ApiResponse::success(null, 'Password reset successfully. Please log in again.', 'AUTH_RESET_COMPLETE');
    }
}
