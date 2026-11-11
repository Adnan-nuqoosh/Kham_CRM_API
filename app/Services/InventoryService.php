<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function reserve(
        int $variantId,
        int $warehouseId,
        int $quantity,
        ?int $referenceId = null,
    ): InventoryStock {
        return DB::transaction(function () use ($variantId, $warehouseId, $quantity, $referenceId) {
            $stock = $this->lockStock($variantId, $warehouseId);

            if ($quantity < 1 || $stock->available < $quantity) {
                throw ValidationException::withMessages([
                    'items' => [
                        "Insufficient stock for variant #{$variantId} in the selected warehouse. Available: {$stock->available}.",
                    ],
                    'quantity' => [
                        "Insufficient stock. Available: {$stock->available}.",
                    ],
                ]);
            }

            $stock->increment('reserved', $quantity);
            $stock->refresh();

            InventoryMovement::create([
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => MovementType::Reservation,
                'quantity' => -$quantity,
                'reference_type' => 'order',
                'reference_id' => $referenceId,
                'note' => 'Stock reserved',
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            return $stock;
        });
    }

    public function release(
        int $variantId,
        int $warehouseId,
        int $quantity,
        ?int $referenceId = null,
    ): void {
        DB::transaction(function () use ($variantId, $warehouseId, $quantity, $referenceId) {
            $stock = $this->lockStock($variantId, $warehouseId);
            if ($quantity < 1 || $stock->reserved < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['Cannot release more stock than is currently reserved.'],
                ]);
            }

            $stock->update([
                'reserved' => $stock->reserved - $quantity,
            ]);

            InventoryMovement::create([
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => MovementType::Release,
                'quantity' => $quantity,
                'reference_type' => 'order',
                'reference_id' => $referenceId,
                'note' => 'Reservation released',
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);
        });
    }

    public function commitSale(
        int $variantId,
        int $warehouseId,
        int $quantity,
        ?int $referenceId = null,
    ): void {
        DB::transaction(function () use ($variantId, $warehouseId, $quantity, $referenceId) {
            $stock = $this->lockStock($variantId, $warehouseId);

            if ($quantity < 1 || $stock->on_hand < $quantity || $stock->reserved < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['Cannot ship stock without sufficient on-hand quantity and an existing reservation.'],
                ]);
            }

            $stock->update([
                'on_hand' => $stock->on_hand - $quantity,
                'reserved' => $stock->reserved - $quantity,
            ]);

            InventoryMovement::create([
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'type' => MovementType::Sale,
                'quantity' => -$quantity,
                'reference_type' => 'order',
                'reference_id' => $referenceId,
                'note' => 'Stock sold',
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);
        });
    }

    private function lockStock(int $variantId, int $warehouseId): InventoryStock
    {
        $stock = InventoryStock::query()
            ->where('variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            throw ValidationException::withMessages([
                'warehouse_id' => [
                    'No inventory record exists for this product in the selected warehouse. Add stock for that warehouse on the Inventory page (or when editing the product), then try again.',
                ],
                'items' => [
                    "Missing inventory for variant #{$variantId} in warehouse #{$warehouseId}.",
                ],
            ]);
        }

        return $stock;
    }
}
