# KHAM Commerce API — Apidog Testing Guide

## 1. Run the Laravel API locally

Windows example:

```bat
cd C:\xampp\htdocs\Kham_CRM_API
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan optimize:clear
php artisan serve --host=127.0.0.1 --port=8000
```

Create the MySQL database `kham_commerce` before running migrations, or change the DB values in `.env`.

Local API base URL:

`http://127.0.0.1:8000/api/v1`

## 2. Import in Apidog

Import `docs/KHAM_Commerce_API_OpenAPI.yaml` as OpenAPI/Swagger.

## 3. Login first

`POST /auth/login`

```json
{
  "email": "admin@example.com",
  "password": "ChangeMe123!",
  "device_name": "Apidog"
}
```

Use the `ADMIN_EMAIL` and `ADMIN_PASSWORD` values from your `.env` if you changed them.

The token is returned at `data.token`.

### Optional automatic token extraction in Apidog
Add a Post Processor custom script to Login:

```javascript
pm.test("Login status is 200", function () {
  pm.response.to.have.status(200);
});
const body = pm.response.json();
pm.environment.set("AUTH_TOKEN", body.data.token);
```

For authenticated requests use Bearer token `{{AUTH_TOKEN}}`.

## 4. Recommended test order

1. Login
2. GET `/auth/me`
3. GET `/markets`, `/currencies`, `/warehouses`
4. POST `/categories`
5. POST `/brands`
6. POST `/products`
7. POST `/prices/upsert`
8. POST `/inventory/adjust`
9. POST `/customers`
10. POST `/coupons` (optional)
11. POST `/orders`
12. GET `/orders/{id}`
13. PATCH `/orders/{id}/status` to `confirmed`, `packed`, then `shipped`
14. GET `/inventory` and `/inventory/movements` to verify reservation/sale movements
15. GET `/dashboard`

## 5. Important

A product needs a variant, a market-specific price, and enough stock in the selected warehouse before an order can be created successfully.
