<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Support\ApiResponse;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $sevenDaysAgo = now()->subDays(6)->startOfDay();

        $salesLast7Days = Order::query()
            ->where('placed_at', '>=', $sevenDaysAgo)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->selectRaw('DATE(placed_at) as date, COUNT(*) as orders, SUM(grand_total) as revenue')
            ->groupByRaw('DATE(placed_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'orders' => (int) $row->orders,
                'revenue' => (float) $row->revenue,
            ]);

        $data = [
            'orders_today' => Order::where('placed_at', '>=', $today)->count(),
            'revenue_today' => (float) Order::where('placed_at', '>=', $today)
                ->whereNotIn('status', ['cancelled', 'returned'])
                ->sum('grand_total'),
            'orders_week' => Order::where('placed_at', '>=', $weekStart)->count(),
            'revenue_week' => (float) Order::where('placed_at', '>=', $weekStart)
                ->whereNotIn('status', ['cancelled', 'returned'])
                ->sum('grand_total'),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'customers_total' => Customer::count(),
            'active_products' => Product::where('status', 'active')->count(),
            'low_stock_skus' => InventoryStock::whereRaw('(on_hand - reserved - damaged) <= reorder_level')->count(),
            'orders_by_status' => Order::selectRaw('status, COUNT(*) total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($value) => (int) $value),
            'sales_last_7_days' => $salesLast7Days,
            'products_by_status' => Product::selectRaw('status, COUNT(*) total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($value) => (int) $value),
        ];

        return ApiResponse::success($data, 'Dashboard fetched.', 'DASHBOARD');
    }
}
