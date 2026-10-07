<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryAdjustRequest;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryStock::with(['variant.product', 'warehouse.market']);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->boolean('low_stock')) {
            $query->whereRaw('(on_hand - reserved - damaged) <= reorder_level');
        }

        $paginator = $query->paginate(PaginationMeta::perPage($request, 50));

        return ApiResponse::success(
            $paginator->items(),
            'Inventory fetched.',
            'INVENTORY_LIST',
            200,
            PaginationMeta::from($paginator)
        );
    }

    public function adjust(InventoryAdjustRequest $request)
    {
        $stock = DB::transaction(function () use ($request) {
            $stock = InventoryStock::firstOrCreate(
                [
                    'variant_id' => $request->variant_id,
                    'warehouse_id' => $request->warehouse_id,
                ],
                [
                    'on_hand' => 0,
                    'reserved' => 0,
                    'damaged' => 0,
                    'reorder_level' => 10,
                ],
            );

            $stock = InventoryStock::whereKey($stock->id)->lockForUpdate()->first();
            $new = $stock->on_hand + $request->quantity_delta;

            if ($new < 0) {
                throw ValidationException::withMessages([
                    'quantity_delta' => ['Adjustment would make on-hand stock negative.'],
                ]);
            }

            $stock->update(['on_hand' => $new]);

            InventoryMovement::create([
                'variant_id' => $request->variant_id,
                'warehouse_id' => $request->warehouse_id,
                'type' => MovementType::Adjustment,
                'quantity' => $request->quantity_delta,
                'note' => $request->reason,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            return $stock->fresh(['variant.product', 'warehouse']);
        });

        return ApiResponse::success($stock, 'Inventory adjusted.', 'INVENTORY_ADJUSTED');
    }

    public function movements(Request $request)
    {
        $query = InventoryMovement::query()
            ->with(['variant.product', 'warehouse'])
            ->latest('created_at');

        foreach (['variant_id', 'warehouse_id', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->$field);
            }
        }

        $paginator = $query->paginate(PaginationMeta::perPage($request, 50));

        return ApiResponse::success(
            $paginator->items(),
            'Inventory movements fetched.',
            'INVENTORY_MOVEMENTS',
            200,
            PaginationMeta::from($paginator)
        );
    }
}
