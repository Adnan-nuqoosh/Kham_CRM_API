<?php
namespace App\Services;
use App\Models\Coupon;
use Illuminate\Validation\ValidationException;
class CouponService {
 public function discount(?string $code,float $subtotal):float {
  if(!$code)return 0;
  $coupon=Coupon::query()->whereRaw('UPPER(code)=?',[strtoupper($code)])->where('is_active',true)->first();
  if(!$coupon)throw ValidationException::withMessages(['coupon_code'=>['Coupon is invalid.']]);
  if($coupon->starts_at&&now()->lt($coupon->starts_at)||$coupon->ends_at&&now()->gt($coupon->ends_at))throw ValidationException::withMessages(['coupon_code'=>['Coupon is not currently active.']]);
  if($coupon->min_spend&&$subtotal<(float)$coupon->min_spend)throw ValidationException::withMessages(['coupon_code'=>['Minimum spend requirement is not met.']]);
  $discount=$coupon->type==='percentage'?$subtotal*((float)$coupon->value/100):(float)$coupon->value;
  if($coupon->max_discount)$discount=min($discount,(float)$coupon->max_discount);
  return round(min($discount,$subtotal),4);
 }
}
