<?php

namespace App\Services;

use App\Models\Market;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

class FulfillmentWarehouseResolver
{
    public function forMarket(int $marketId): Warehouse
    {
        $market = Market::query()->whereKey($marketId)->where('is_active', true)->first();

        if (! $market) {
            throw ValidationException::withMessages([
                'market_id' => ['The selected sales market is not active.'],
            ]);
        }

        $code = (string) config('commerce.fulfillment_warehouse_code', 'EG-01');

        // A configured warehouse must be active and physically associated with Egypt.
        $warehouse = Warehouse::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->whereHas('market', fn ($query) => $query->where('country_code', 'EG'))
            ->first();

        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The Egypt fulfillment warehouse is not configured or is inactive. Contact an administrator.'],
            ]);
        }

        return $warehouse;
    }
}
