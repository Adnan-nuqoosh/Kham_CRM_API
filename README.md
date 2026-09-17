KHAM Commerce OS — Backend API

API-first Laravel 12 backend for a multi-market commerce operation. The frontend/admin panel can be replaced later without moving business logic out of the backend.

Included in Build 1

Sanctum token authentication

Spatie roles/permissions foundation

Standard API response envelope

Products, brands, categories, variants

Multi-market / multi-currency pricing

Warehouses and inventory stock ledger

Customers and addresses

Orders with server-side price resolution

Inventory reservation / release / sale commit workflow

Coupons foundation

Payments and shipment data model

Dashboard KPIs

Audit-log database foundation

Egypt, UAE and KSA seed markets

Requirements

PHP 8.3+

Composer 2

MySQL 8+

Install

cp .env.example .env
composer install
php artisan key:generate
# Create MySQL database named kham_commerce (or edit .env)
php artisan migrate --seed
php artisan serve

Login using ADMIN_EMAIL and ADMIN_PASSWORD from .env. Change the seeded password immediately on non-local environments.

Response contract

{
  "success": true,
  "code": "ORDER_CREATED",
  "message": "Order created and inventory reserved.",
  "data": {},
  "meta": {},
  "errors": {}
}

Architecture decisions

Prices are resolved by backend from variant + market; clients cannot decide selling price.

Stock uses on_hand, reserved, damaged, and computed available quantities.

Every stock operation has an immutable movement record.

Market, currency, warehouse and price are separate domain concepts.

Orders snapshot product name/SKU/prices so historic orders survive later catalog edits.

API is versioned under /api/v1.

Next production modules

Purchase Orders/Suppliers, stock transfers, shipping-rate engine + courier API adapters, returns/RMA/refunds, coupon usage limits/redemptions, customer loyalty/wallet, localized product content, tax engine, payment gateway adapters/webhooks, media upload service, audit observers, notifications/queues, OpenAPI docs and automated feature tests.
