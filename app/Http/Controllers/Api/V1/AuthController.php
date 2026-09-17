<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
class AuthController extends Controller {
 public function login(LoginRequest $request){$user=User::where('email',$request->email)->first();if(!$user||!Hash::check($request->password,$user->password))return ApiResponse::error('Invalid credentials.','AUTH_INVALID',401,['email'=>['The provided credentials are incorrect.']]);$token=$user->createToken($request->device_name?:'admin')->plainTextToken;return ApiResponse::success(['token'=>$token,'token_type'=>'Bearer','user'=>$user->load('roles.permissions')],'Authenticated.','AUTH_LOGIN');}
 public function me(Request $request){return ApiResponse::success($request->user()->load('roles.permissions'));}
 public function logout(Request $request){$request->user()->currentAccessToken()?->delete();return ApiResponse::success(null,'Logged out.','AUTH_LOGOUT');}
}
