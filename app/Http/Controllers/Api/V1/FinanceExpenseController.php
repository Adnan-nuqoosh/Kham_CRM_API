<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinanceExpense;
use App\Models\Market;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceExpenseController extends Controller
{
    public function index(Request $request)
    {
        $q = FinanceExpense::query();
        if ($request->filled('market_id')) $q->where('market_id', $request->integer('market_id'));
        if ($request->filled('from')) $q->whereDate('incurred_on', '>=', $request->input('from'));
        if ($request->filled('to')) $q->whereDate('incurred_on', '<=', $request->input('to'));
        if ($request->filled('category')) $q->where('category', $request->input('category'));
        return ApiResponse::success($q->orderByDesc('incurred_on')->orderByDesc('id')->paginate(25), 'Expenses fetched.', 'FINANCE_EXPENSES');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'market_id' => ['required', 'integer', 'exists:markets,id'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'incurred_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $market = Market::with('currency')->findOrFail($data['market_id']);
        if (!$market->currency || !$market->is_active) {
            throw ValidationException::withMessages(['market_id' => 'A valid active market with currency is required.']);
        }
        $data['currency_code'] = $market->currency->code;
        $data['created_by'] = $request->user()->id;
        $expense = FinanceExpense::create($data);
        return ApiResponse::success($expense, 'Expense created.', 'FINANCE_EXPENSE_CREATED', 201);
    }

    public function update(Request $request, FinanceExpense $expense)
    {
        $data = $request->validate([
            'category' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'required', 'string', 'max:500'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:999999999999'],
            'incurred_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $expense->update($data);
        return ApiResponse::success($expense->fresh(), 'Expense updated.', 'FINANCE_EXPENSE_UPDATED');
    }

    public function destroy(FinanceExpense $expense)
    {
        $expense->delete();
        return ApiResponse::success(null, 'Expense archived.', 'FINANCE_EXPENSE_ARCHIVED');
    }

    public function setItemCost(Request $request, \App\Models\OrderItem $item)
    {
        $data = $request->validate(['unit_cost_snapshot' => ['required', 'numeric', 'min:0', 'max:999999999999']]);
        $item->unit_cost_snapshot = $data['unit_cost_snapshot'];
        $item->save();
        return ApiResponse::success($item->fresh(), 'Historical item cost saved.', 'FINANCE_ITEM_COST_UPDATED');
    }
}
