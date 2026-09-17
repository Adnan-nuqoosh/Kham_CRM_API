# KHAM Commerce API v1
Base URL: `/api/v1`
Authentication: `Authorization: Bearer <token>`

## Auth
- `POST /auth/login`
- `GET /auth/me`
- `POST /auth/logout`

## Dashboard
- `GET /dashboard`

## Products / Catalog
- `GET /products?search=&status=&per_page=`
- `POST /products`
- `GET /products/{id}`
- `PUT/PATCH /products/{id}`
- `DELETE /products/{id}` (soft delete)
- `GET|POST /categories`
- `GET|POST /brands`
- `GET /currencies`
- `GET /markets`
- `GET /warehouses`
- `POST /prices/upsert`

## Inventory
- `GET /inventory?warehouse_id=&low_stock=1`
- `POST /inventory/adjust`
- `GET /inventory/movements`

## Customers
- `GET /customers`
- `POST /customers`
- `GET /customers/{id}`
- `PATCH /customers/{id}`

## Coupons
- `GET /coupons`
- `POST /coupons`
- `PATCH /coupons/{id}`

## Orders
- `GET /orders`
- `POST /orders`
- `GET /orders/{id}`
- `PATCH /orders/{id}/status`

## Important order behavior
1. Order creation obtains the active market price server-side.
2. Client-submitted prices are ignored.
3. Inventory is reserved inside a DB transaction.
4. Cancelling releases reservations.
5. Shipping commits the reserved quantity as a sale.
