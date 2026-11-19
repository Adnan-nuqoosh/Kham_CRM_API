<?php

namespace App\Services;

use App\Models\Coupon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CouponService
{
    /**
     * Preview or validate a coupon. A checkout transaction must pass
     * lockForRedemption=true and call redeem() before the transaction commits.
     * Returns only a provisional discount for callers outside a transaction.
     */
    public function discount(
        ?string $code,
        float $subtotal,
        ?int $customerId = null,
        bool $lockForRedemption = false,
        ?int $marketId = null
    ): float {
        if ($code === null || trim($code) === '') {
            return 0.0;
        }
        $normalized = strtoupper(trim($code));
        $query = Coupon::query()->whereRaw('UPPER(code) = ?', [$normalized]);
        if ($lockForRedemption) {
            $query->lockForUpdate();
        }
        $coupon = $query->first();
        if (!$coupon || !$coupon->is_active) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon is invalid.']]);
        }
        if (($coupon->starts_at && now()->lt($coupon->starts_at))
            || ($coupon->ends_at && now()->gt($coupon->ends_at))) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon is not currently active.']]);
        }
        // Only explicit market restrictions are understood. Never silently
        // ignore arbitrary product/customer targeting constraints.
        $rules = $coupon->rules ?? [];
        if (!is_array($rules) || array_diff(array_keys($rules), ['market_ids'])) {
            throw ValidationException::withMessages(['coupon_code' => ['Unsupported coupon eligibility rules.']]);
        }
        $marketIds = $rules['market_ids'] ?? [];
        if (!is_array($marketIds) || array_filter($marketIds,
            fn ($id) => !is_int($id) || $id < 1)) {
            throw ValidationException::withMessages(['coupon_code' => ['Invalid market eligibility rules.']]);
        }
        if ($marketIds !== []) {
            if ($marketId === null || !in_array($marketId, $marketIds, true)) {
                throw ValidationException::withMessages(['coupon_code' => ['Coupon is not eligible for this market.']]);
            }
        }
        // A currency-neutral numeric fixed discount would be incorrect across
        // EGP/AED/SAR. Require an explicitly targeted SINGLE market.
        if ($coupon->type === 'fixed' && (count($marketIds) !== 1 || $marketId === null)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Fixed coupons require exactly one configured market.'],
            ]);
        }
        if ($coupon->min_spend !== null && $subtotal < (float) $coupon->min_spend) {
            throw ValidationException::withMessages(['coupon_code' => ['Minimum spend requirement is not met.']]);
        }

        // Only non-cancelled/non-returned orders consume a usage slot. The
        // checkout holds the coupon row lock through creation and redemption.
        $uses = DB::table('coupon_redemptions AS cr')
            ->join('orders AS o', 'o.id', '=', 'cr.order_id')
            ->where('cr.coupon_id', $coupon->id)
            ->whereNotIn('o.status', ['cancelled', 'returned']);
        if ($coupon->max_uses !== null
            && (clone $uses)->count() >= (int) $coupon->max_uses) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon usage limit reached.']]);
        }
        if ($coupon->max_uses_per_customer !== null) {
            if (!$customerId) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['Sign in to verify this customer-limited coupon.'],
                ]);
            }
            if ((clone $uses)->where('cr.customer_id', $customerId)->count()
                >= (int) $coupon->max_uses_per_customer) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['Coupon usage limit reached for this customer.'],
                ]);
            }
        }
        $discount = $coupon->type === 'percentage'
            ? $subtotal * ((float) $coupon->value / 100)
            : (float) $coupon->value;
        if (!in_array($coupon->type, ['fixed', 'percentage'], true)) {
            throw ValidationException::withMessages(['coupon_code' => ['Coupon type is invalid.']]);
        }
        if ($coupon->max_discount !== null) {
            $discount = min($discount, (float) $coupon->max_discount);
        }
        return round(max(0.0, min($discount, $subtotal)), 4);
    }

    /**
     * Called only inside OrderService's transaction, while the coupon row
     * is locked and after the new order is persisted.
     */
    public function redeem(string $code, ?int $customerId, int $orderId, float $discount): void
    {
        if ($discount <= 0) {
            return;
        }
        $coupon = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])
            ->lockForUpdate()->firstOrFail();
        DB::table('coupon_redemptions')->insert([
            'coupon_id' => $coupon->id,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'discount_amount' => $discount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
