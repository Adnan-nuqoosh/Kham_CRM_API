<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 30), 1), 100);

        return ApiResponse::success(Coupon::latest()->paginate($perPage));
    }

    public function show(Coupon $coupon)
    {
        return ApiResponse::success($coupon, 'Coupon fetched.', 'COUPON_DETAIL');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:80|unique:coupons,code',
            'name' => 'required|string|max:255',
            'type' => 'required|in:fixed,percentage',
            'value' => 'required|numeric|min:0',
            'min_spend' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_customer' => 'nullable|integer|min:1',
            'rules' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $data['code'] = strtoupper($data['code']);

        return ApiResponse::success(Coupon::create($data), 'Coupon created.', 'COUPON_CREATED', 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $coupon->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:fixed,percentage',
            'value' => 'sometimes|numeric|min:0',
            'min_spend' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_customer' => 'nullable|integer|min:1',
            'rules' => 'nullable|array',
            'is_active' => 'boolean',
        ]));

        return ApiResponse::success($coupon->fresh(), 'Coupon updated.', 'COUPON_UPDATED');
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return ApiResponse::success(null, 'Coupon deleted.', 'COUPON_DELETED');
    }
}
