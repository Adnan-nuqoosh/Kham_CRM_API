<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class StorefrontProductBadgeService
{
    /**
     * Market-scoped, delivered-unit bestseller rule. Never count pending,
     * cancelled, returned, or cross-market orders.
     */
    public function badges(Product $product, int $marketId): array
    {
        $badges = [];
        $newDays = max(1, (int) config('commerce.badges.new_days', 30));
        if ($product->created_at && $product->created_at->betweenIncluded(now()->subDays($newDays), now())) {
            $badges[] = 'NEW';
        }

        $minimumUnits = max(1, (int) config('commerce.badges.bestseller_min_delivered_units', 10));
        $lookbackDays = max(1, (int) config('commerce.badges.bestseller_lookback_days', 90));
        $units = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)
            ->where('orders.market_id', $marketId)
            ->where('orders.status', 'delivered')
            ->where('orders.placed_at', '>=', now()->subDays($lookbackDays))
            ->sum('order_items.quantity');
        if ((int) $units >= $minimumUnits) {
            $badges[] = 'BESTSELLER';
        }
        return $badges;
    }
}
