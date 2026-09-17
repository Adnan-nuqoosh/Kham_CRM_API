# Apidog Setup & Smoke Test — KHAM Commerce API

## 1. Run the Laravel API locally

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Default local base URL:

```text
http://127.0.0.1:8000/api/v1
```

Default seed login (unless changed in `.env`):

```text
admin@example.com
ChangeMe123!
```

Change this password before any public deployment.

## 2. Import into Apidog

Import `docs/openapi.json` as **OpenAPI/Swagger**. The endpoints are grouped by tags (Auth, Products, Inventory, Orders, etc.).

Create/select a Local environment and set the API base URL to:

```text
http://127.0.0.1:8000/api/v1
```

## 3. Authentication

Run `POST /auth/login` first. Copy `data.token` from the response.

In Apidog, set Bearer Token authentication for the environment/project using that token. The OpenAPI definition already declares Bearer authentication, so protected endpoints will inherit it.

## 4. Recommended smoke-test order

1. POST `/auth/login`
2. GET `/auth/me`
3. GET `/currencies`
4. GET `/markets`
5. GET `/warehouses`
6. POST `/categories`
7. POST `/brands`
8. POST `/products` and note the created variant ID
9. POST `/prices/upsert`
10. POST `/inventory/adjust`
11. POST `/customers`
12. POST `/coupons` (optional)
13. POST `/orders`
14. GET `/orders/{id}`
15. PATCH `/orders/{id}/status` → `confirmed`, then `packed`, then `shipped`
16. GET `/inventory/movements` and verify reservation/sale movements
17. GET `/dashboard`

## 5. Expected response envelope

```json
{
  "success": true,
  "code": "ORDER_CREATED",
  "message": "Order created and inventory reserved.",
  "data": {},
  "meta": {},
  "errors": {}
}
```

## 6. Before GitHub push

Never commit `.env`, `vendor/`, logs, generated cache files, or real credentials. `.gitignore` is included in the project.
