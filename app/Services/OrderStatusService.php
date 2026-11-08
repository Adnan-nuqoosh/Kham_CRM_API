<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    // A shipment can only be committed once. No reopening terminal states.
    private const ALLOWED = [
        'pending' => ['confirmed', 'processing', 'packed', 'shipped', 'cancelled'],
        'confirmed' => ['processing', 'packed', 'shipped', 'cancelled'],
        'processing' => ['packed', 'shipped', 'cancelled'],
        'packed' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'returned'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    public function transition(Order $order, OrderStatus $next): Order
    {
        return DB::transaction(function () use ($order, $next) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            // Retrying the same request must not mutate inventory again.
            if ($current === $next) {
                return $locked->load('items');
            }

            if (! in_array($next->value, self::ALLOWED[$current->value] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Invalid order status transition: {$current->value} to {$next->value}."],
                ]);
            }

            $inventory = app(InventoryService::class);
            $locked->load('items');

            if ($next === OrderStatus::Cancelled) {
                foreach ($locked->items as $item) {
                    $inventory->release($item->variant_id, $locked->warehouse_id, $item->quantity, $locked->id);
                }
            }

            if ($next === OrderStatus::Shipped) {
                foreach ($locked->items as $item) {
                    $inventory->commitSale($item->variant_id, $locked->warehouse_id, $item->quantity, $locked->id);
                }
            }

            // Returns do not automatically restock. A separate inspected return
            // receipt must decide whether the item is sellable or damaged.
            $locked->update([
                'status' => $next,
                'fulfillment_status' => match ($next) {
                    OrderStatus::Packed => 'packed',
                    OrderStatus::Shipped => 'shipped',
                    OrderStatus::Delivered => 'delivered',
                    OrderStatus::Cancelled => 'cancelled',
                    OrderStatus::Returned => 'returned',
                    default => $locked->fulfillment_status,
                },
            ]);

            return $locked->fresh('items');
        });
    }
}
