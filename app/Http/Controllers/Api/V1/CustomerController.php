<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::withCount('orders')->withSum('orders', 'grand_total');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('email', 'like', '%' . $search . '%')
                ->orWhere('phone', 'like', '%' . $search . '%')
                ->orWhere('first_name', 'like', '%' . $search . '%')
                ->orWhere('last_name', 'like', '%' . $search . '%')
            );
        }

        $paginator = $query->latest()->paginate(PaginationMeta::perPage($request, 20));

        return ApiResponse::success(
            $paginator->items(),
            'Customers fetched.',
            'CUSTOMER_LIST',
            200,
            PaginationMeta::from($paginator)
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:20',
            'tags' => 'nullable|array',
            'notes' => 'nullable|string',
            'marketing_opt_in' => 'boolean',
        ]);

        return ApiResponse::success(
            Customer::create($data),
            'Customer created.',
            'CUSTOMER_CREATED',
            201
        );
    }

    public function show(Customer $customer)
    {
        return ApiResponse::success($customer->load(['addresses', 'orders.items']));
    }

    public function update(Request $request, Customer $customer)
    {
        $customer->update($request->validate([
            'first_name' => 'sometimes|string|max:120',
            'last_name' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'status' => 'nullable|string|max:20',
            'tags' => 'nullable|array',
            'notes' => 'nullable|string',
            'marketing_opt_in' => 'boolean',
        ]));

        return ApiResponse::success($customer->fresh(), 'Customer updated.', 'CUSTOMER_UPDATED');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return ApiResponse::success(null, 'Customer deleted.', 'CUSTOMER_DELETED');
    }
}
