<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OrderStoreRequest;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with([
            'customer',
            'storeCustomer',
            'market.currency',
            'warehouse',
        ])->withCount('items');

        foreach ([
            'status',
            'payment_status',
            'market_id',
            'warehouse_id',
        ] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('search')) {
            $query->where(
                'order_number',
                'like',
                '%' . $request->input('search') . '%'
            );
        }

        $paginator = $query
            ->latest('id')
            ->paginate(PaginationMeta::perPage($request, 25));

        return ApiResponse::success(
            $paginator->items(),
            'Orders fetched.',
            'ORDER_LIST',
            200,
            PaginationMeta::from($paginator)
        );
    }

    public function store(
        OrderStoreRequest $request,
        OrderService $service
    ) {
        return ApiResponse::success(
            $service->create($request->validated()),
            'Order created and inventory reserved.',
            'ORDER_CREATED',
            201
        );
    }

    public function show(Order $order)
    {
        return ApiResponse::success(
            $order->load([
                'customer',
                'storeCustomer',
                'market.currency',
                'warehouse',
                'items',
                'addresses',
                'payments',
                'shipments',
            ])
        );
    }

    public function status(
        Request $request,
        Order $order,
        InventoryService $inventory
    ) {
        $validated = $request->validate([
            'status' => [
                'required',
                'in:pending,confirmed,processing,packed,shipped,delivered,cancelled,returned',
            ],
        ]);

        $next = OrderStatus::from($validated['status']);

        $updatedOrder = DB::transaction(function () use (
            $order,
            $next,
            $inventory
        ) {
            // Lock the order to prevent simultaneous status changes.
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $current = $lockedOrder->status;

            // Repeating the same status must not change inventory.
            if ($current === $next) {
                return $lockedOrder;
            }

            $this->validateTransition($current, $next);

            $lockedOrder->load('items');

            // Release reserved stock only before shipment.
            if ($next === OrderStatus::Cancelled) {
                foreach ($lockedOrder->items as $item) {
                    $inventory->release(
                        $item->variant_id,
                        $lockedOrder->warehouse_id,
                        $item->quantity,
                        $lockedOrder->id
                    );
                }
            }

            // Deduct physical stock exactly once when shipping.
            if ($next === OrderStatus::Shipped) {
                foreach ($lockedOrder->items as $item) {
                    $inventory->commitSale(
                        $item->variant_id,
                        $lockedOrder->warehouse_id,
                        $item->quantity,
                        $lockedOrder->id
                    );
                }
            }

            $fulfillmentStatus = match ($next) {
                OrderStatus::Packed => 'packed',
                OrderStatus::Shipped => 'shipped',
                OrderStatus::Delivered => 'delivered',
                OrderStatus::Cancelled => 'cancelled',
                default => $lockedOrder->fulfillment_status,
            };

            $lockedOrder->update([
                'status' => $next,
                'fulfillment_status' => $fulfillmentStatus,
            ]);

            return $lockedOrder;
        }, 3);

        return ApiResponse::success(
            $updatedOrder->fresh(['items']),
            'Order status updated.',
            'ORDER_STATUS_UPDATED'
        );
    }

    private function validateTransition(
        OrderStatus $current,
        OrderStatus $next
    ): void {
        // Cancelled and delivered orders are terminal for now.
        if (in_array($current, [
            OrderStatus::Cancelled,
            OrderStatus::Delivered,
            OrderStatus::Returned,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => [
                    'This order is already in a terminal status.',
                ],
            ]);
        }

        // Returns need a separate verified return/restocking flow.
        if ($next === OrderStatus::Returned) {
            throw ValidationException::withMessages([
                'status' => [
                    'Returns are not yet supported by this status endpoint.',
                ],
            ]);
        }

        if ($current === OrderStatus::Shipped) {
            if ($next !== OrderStatus::Delivered) {
                throw ValidationException::withMessages([
                    'status' => [
                        'A shipped order can only be marked delivered.',
                    ],
                ]);
            }

            return;
        }

        // Pre-shipment statuses must never move backwards.
        $progress = [
            'pending' => 0,
            'confirmed' => 1,
            'processing' => 2,
            'packed' => 3,
            'shipped' => 4,
        ];

        if ($next === OrderStatus::Cancelled) {
            return;
        }

        if (
            $next === OrderStatus::Delivered ||
            !isset($progress[$current->value]) ||
            !isset($progress[$next->value]) ||
            $progress[$next->value] <= $progress[$current->value]
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    'Invalid order status transition.',
                ],
            ]);
        }
    }
}
