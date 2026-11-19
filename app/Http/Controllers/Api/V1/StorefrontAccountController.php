<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\StorefrontAccount;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

final class StorefrontAccountController extends Controller
{
    public function register(Request $request)
    {
        $request->merge(['email'=>mb_strtolower(trim((string)$request->input('email','')))]);
        $data = $request->validate([
            'first_name' => ['required','string','max:100'],
            'last_name' => ['nullable','string','max:100'],
            'email' => ['required','email','max:255','unique:storefront_accounts,email'],
            'phone' => ['required','string','max:30'],
            'password' => ['required','confirmed',Password::min(12)],
        ]);
        $email = mb_strtolower(trim($data['email']));
        // Do not silently claim a pre-existing CRM customer record.
        $account = DB::transaction(function () use ($data, $email) {
            $customer = Customer::create([
                'first_name'=>$data['first_name'], 'last_name'=>$data['last_name'] ?? null,
                'email'=>$email, 'phone'=>$data['phone'], 'marketing_opt_in'=>false,
            ]);
            return StorefrontAccount::create([
                'customer_id'=>$customer->id, 'email'=>$email,
                'password'=>$data['password'],
            ]);
        });
        return ApiResponse::success([
            'token'=>$account->createToken('storefront', ['storefront'], now()->addDays(30))->plainTextToken,
            'token_type'=>'Bearer',
            'customer'=>$account->customer,
        ], 'Storefront account created.', 'STOREFRONT_REGISTERED', 201)->header('Cache-Control','no-store');
    }

    public function login(Request $request)
    {
        $request->merge(['email'=>mb_strtolower(trim((string)$request->input('email','')))]);
        $data=$request->validate(['email'=>['required','email'], 'password'=>['required','string']]);
        $account=StorefrontAccount::where('email',mb_strtolower(trim($data['email'])))->first();
        if (!$account || !Hash::check($data['password'],$account->password)) {
            return ApiResponse::error('Invalid credentials.', 'STOREFRONT_AUTH_INVALID', 401);
        }
        return ApiResponse::success([
            'token'=>$account->createToken('storefront',['storefront'],now()->addDays(30))->plainTextToken,
            'token_type'=>'Bearer', 'customer'=>$account->customer,
        ],'Authenticated.','STOREFRONT_LOGIN')->header('Cache-Control','no-store');
    }

    private function account(Request $request): StorefrontAccount
    {
        $account=$request->user();
        if (!$account instanceof StorefrontAccount || !$account->tokenCan('storefront')) {
            abort(403,'Storefront customer account required.');
        }
        return $account;
    }

    public function me(Request $request)
    {
        return ApiResponse::success($this->account($request)->customer,'Customer fetched.','STOREFRONT_ME')
            ->header('Cache-Control','no-store');
    }

    /**
     * Update storefront customer profile. Email changes deliberately require a
     * separate verified-email workflow and cannot be made through this route.
     */
    public function updateProfile(Request $request)
    {
        $account = $this->account($request);
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'email' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'password' => ['prohibited'],
            'marketing_opt_in' => ['prohibited'],
        ]);
        $customer = DB::transaction(function () use ($account, $data) {
            $customer = Customer::query()->whereKey($account->customer_id)->lockForUpdate()->firstOrFail();
            $customer->update($data);
            return $customer->fresh();
        });
        return ApiResponse::success($customer, 'Storefront profile updated.', 'STOREFRONT_PROFILE_UPDATED')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Verify the current password before changing it. Revoke other sessions
     * immediately; the current token remains valid until customer logs out.
     */
    public function changePassword(Request $request)
    {
        $account = $this->account($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        if (!Hash::check($data['current_password'], $account->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }
        if (Hash::check($data['password'], $account->password)) {
            throw ValidationException::withMessages([
                'password' => ['New password must differ from current password.'],
            ]);
        }
        $account->forceFill(['password' => $data['password']])->save();
        $currentToken = $account->currentAccessToken();
        if ($currentToken instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $account->tokens()->where('id', '<>', $currentToken->id)->delete();
        } else {
            $account->tokens()->delete();
        }
        return ApiResponse::success(null, 'Password updated; other sessions revoked.', 'STOREFRONT_PASSWORD_CHANGED')
            ->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request)
    {
        $account=$this->account($request);
        $account->currentAccessToken()?->delete();
        return ApiResponse::success(null,'Logged out.','STOREFRONT_LOGOUT')->header('Cache-Control','no-store');
    }
}
