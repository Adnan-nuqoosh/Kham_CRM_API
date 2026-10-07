<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = now()->startOfDay();
        $weekStart = now()->subDays(6)->startOfDay();

        $ordersLast7 = Order::query()
            ->where('placed_at', '>=', $weekStart)
            ->selectRaw('DATE(placed_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $revenueLast7 = Order::query()
            ->where('placed_at', '>=', $weekStart)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->selectRaw('DATE(placed_at) as day, COALESCE(SUM(grand_total), 0) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $days[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('D'),
                'orders' => (int) ($ordersLast7[$date] ?? 0),
                'revenue' => (float) ($revenueLast7[$date] ?? 0),
            ];
        }

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
            'low_stock_skus' => InventoryStock::whereRaw(
                '(on_hand - reserved - damaged) <= reorder_level'
            )->count(),
            'orders_by_status' => Order::selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($value) => (int) $value),
            'products_by_status' => Product::selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($value) => (int) $value),
            'sales_last_7_days' => $days,
        ];

        return ApiResponse::success($data, 'Dashboard fetched.', 'DASHBOARD');
    }
}
