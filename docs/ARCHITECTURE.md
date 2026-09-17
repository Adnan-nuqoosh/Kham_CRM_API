# Architecture

```mermaid
erDiagram
  CURRENCY ||--o{ MARKET : default_currency
  MARKET ||--o{ WAREHOUSE : owns
  BRAND ||--o{ PRODUCT : groups
  CATEGORY ||--o{ PRODUCT : classifies
  PRODUCT ||--o{ PRODUCT_VARIANT : has
  PRODUCT_VARIANT ||--o{ PRODUCT_MARKET_PRICE : priced_in
  MARKET ||--o{ PRODUCT_MARKET_PRICE : market
  CURRENCY ||--o{ PRODUCT_MARKET_PRICE : currency
  PRODUCT_VARIANT ||--o{ INVENTORY_STOCK : stocked
  WAREHOUSE ||--o{ INVENTORY_STOCK : stores
  PRODUCT_VARIANT ||--o{ INVENTORY_MOVEMENT : ledger
  CUSTOMER ||--o{ CUSTOMER_ADDRESS : owns
  CUSTOMER ||--o{ ORDER : places
  MARKET ||--o{ ORDER : receives
  WAREHOUSE ||--o{ ORDER : fulfills
  ORDER ||--|{ ORDER_ITEM : contains
  ORDER ||--o{ PAYMENT : payments
  ORDER ||--o{ SHIPMENT : shipments
```

## Service boundaries
- `PricingService`: resolves trusted server-side market pricing.
- `InventoryService`: row-lock based reservation, release and sale commits.
- `CouponService`: discount calculation and validity checks.
- `OrderService`: transaction boundary for order pricing, creation and inventory reservation.

## Rules
1. Controllers never trust frontend price values.
2. Inventory changes require a stock movement record.
3. Market configuration is independent from frontend locale.
4. Historic order item snapshots are retained even if catalog data changes.
5. API contracts are versioned under `/api/v1`.
