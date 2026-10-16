<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinanceExpense;
use App\Models\Order;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinanceReportController extends Controller
{
    public function summary(Request $request)
    {
        $filters = $request->validate([
            'period' => ['nullable', 'in:daily,weekly,monthly,custom'],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'market_id' => ['required', 'integer', 'exists:markets,id'],
        ]);

        $period = $filters['period'] ?? 'monthly';
        $now = CarbonImmutable::now();
        [$from, $to] = match ($period) {
            'daily' => [$now->startOfDay(), $now->endOfDay()],
            'weekly' => [$now->startOfWeek(), $now->endOfWeek()],
            'monthly' => [$now->startOfMonth(), $now->endOfMonth()],
            default => [CarbonImmutable::parse($filters['from'])->startOfDay(), CarbonImmutable::parse($filters['to'])->endOfDay()],
        };
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'Maximum reporting range is 366 days.']);
        }

        $marketId = (int) $filters['market_id'];
        $market = \App\Models\Market::with('currency')->findOrFail($marketId);
        $currency = $market->currency?->code;

        // "Sales" is defined as delivered orders; "collected" is the paid subset.
        // Other currencies are never combined into one amount.
        $orders = Order::query()
            ->where('market_id', $marketId)
            ->whereBetween('placed_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'returned']);

        $delivered = (clone $orders)->where('status', 'delivered');
        $sales = (float) (clone $delivered)->sum('grand_total');
        $collected = (float) (clone $delivered)->where('payment_status', 'paid')->sum('grand_total');
        $totalOrders = (clone $orders)->count();
        $deliveredCount = (clone $delivered)->count();

        $expenseQuery = FinanceExpense::query()->where('market_id', $marketId)
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()]);
        $expenses = (float) (clone $expenseQuery)->sum('amount');
        $expenseCategories = (clone $expenseQuery)
            ->selectRaw('category, SUM(amount) AS total')
            ->groupBy('category')->orderBy('category')->get();

        $items = \Illuminate\Support\Facades\DB::table('order_items AS oi')
            ->join('orders AS o', 'o.id', '=', 'oi.order_id')
            ->where('o.market_id', $marketId)
            ->where('o.status', 'delivered')
            ->whereBetween('o.placed_at', [$from, $to]);
        $missingCostItems = (clone $items)->whereNull('oi.unit_cost_snapshot')->count();
        $knownCogs = (float) (clone $items)
            ->whereNotNull('oi.unit_cost_snapshot')
            ->selectRaw('COALESCE(SUM(oi.quantity * oi.unit_cost_snapshot), 0) AS cogs')->value('cogs');

        // Profit is null until every delivered order item has a recorded unit cost.
        $profitReady = $missingCostItems === 0;
        $grossProfit = $profitReady ? round($sales - $knownCogs, 4) : null;
        $netProfit = $profitReady ? round($sales - $knownCogs - $expenses, 4) : null;

        return ApiResponse::success([
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'market_id' => $marketId,
            'currency_code' => $currency,
            'sales_basis' => 'Delivered orders grouped by placed_at date; excludes cancelled and returned orders. Review refunds, tax and shipping policy before financial sign-off.',
            'total_orders' => $totalOrders,
            'delivered_orders' => $deliveredCount,
            'delivered_sales' => round($sales, 4),
            'paid_delivered_sales' => round($collected, 4),
            'operating_expenses' => round($expenses, 4),
            'expense_categories' => $expenseCategories,
            'known_cost_of_goods' => round($knownCogs, 4),
            'missing_cost_items' => $missingCostItems,
            'gross_profit' => $grossProfit,
            'net_profit' => $netProfit,
            'profit_status' => $profitReady ? 'provisional' : 'incomplete_cost_data',
        ], 'Finance report generated.', 'FINANCE_REPORT');
    }
}
