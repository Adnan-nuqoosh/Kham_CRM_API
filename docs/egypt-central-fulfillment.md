# Egypt Central Fulfillment — implementation and release checklist

## Decision
Egypt (warehouse code `EG-01`) is the only physical fulfillment warehouse for sales markets EG, AE, SA. The market still determines selling price and currency. Do not delete AE-01/SA-01 or rewrite historical orders.

## Changes in this PR
- `FulfillmentWarehouseResolver` resolves an **active Egypt warehouse** from `FULFILLMENT_WAREHOUSE_CODE` (default `EG-01`).
- `OrderService` ignores any submitted warehouse ID and stores/reserves against the resolved Egypt warehouse.
- `OrderStoreRequest` keeps `warehouse_id` optional for compatibility with older frontend payloads; it is **not trusted**.
- `OrderStatusService` locks the order row during transition, rejects backwards/invalid transitions, makes same-state retries idempotent, and commits stock on the first transition to shipped.
- Cancel before shipment releases reservation. Returns do **not** automatically restock.

## Existing contract
- `POST /api/v1/orders` stays authenticated with `orders.manage` and retains the existing response envelope.
- Required: `market_id`, `items`, `shipping_address`; optional legacy `warehouse_id` is ignored.
- Existing `PATCH /api/v1/orders/{order}/status` endpoint remains unchanged.

## Deployment prerequisites (blocking)
1. Backup production database and files, and record current inventory totals by warehouse.
2. Confirm `EG-01` exists, is active, and belongs to Egypt. Confirm Egypt inventory records for every sellable variant.
3. Audit open AE/SA orders and reservations before rollout. Existing orders keep their historical warehouse IDs; do **not** move reservations without a reconciliation/migration plan.
4. Confirm there are no external integrations that rely on arbitrary warehouse selection.
5. Run PHP syntax checks, framework boot, route listing, and tests on a staging clone. **No tests were executed by this GitHub-only change.**
6. Test: AE/SA/EG order reserves EG-01; legacy AE-01 input is ignored; insufficient Egypt stock returns 422; cancelled unshipped order releases stock; first shipment decrements once; repeated shipped request leaves stock unchanged; shipped -> processing/cancelled is rejected; delivered -> returned does not restock.
7. Review concurrent order creation, payment retries, tax/shipping trust, and order number generation separately before enabling public guest checkout.
8. Monitor logs, stock balances and inventory movement records after release. Rollback requires reconciliation of orders and reservations created since deployment.

## Out of scope
- Guest checkout (current orders endpoint is admin-only).
- Server-side shipping/tax calculation (existing endpoint still accepts client amounts).
- International customs/taxes, courier integration, return inspection/restocking.
- Consolidating or deleting legacy UAE/KSA inventory.
- Finance and accounting PR, which remains independent.
