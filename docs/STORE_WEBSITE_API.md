# KHAM Beauty Website API — Integration Guide

This is a separate Laravel API application. It must use a separate database and a separate GitHub repository from the existing CRM deployment.

## Requirements
- PHP 8.2+, MySQL/MariaDB, Composer, Laravel 12
- Copy `.env.example` to `.env`; configure an isolated database and `APP_URL`.
- `composer install`, `php artisan key:generate`, `php artisan migrate --force`, `php artisan storage:link`.
- Set `CORS_ALLOWED_ORIGINS` to the actual Nuxt frontend origin.
- Avoid running seeders on a production database without reviewing their effects.

## API
Base path: `/api/v1/store`. JSON responses use `success`, `code`, `message`, `data`, `meta`, `errors`.

| Method | Path | Authentication |
|---|---|---|
| GET | /settings?market=EG | Public |
| GET | /home?market=EG | Public |
| GET | /categories | Public |
| GET | /brands | Public |
| GET | /products?market=EG&q=&brand=&category=&sort=&per_page= | Public |
| GET | /products/{slug}?market=EG | Public |
| GET | /ingredients | Public |
| GET | /rituals | Public |
| GET | /content | Public |
| POST | /cart/quote | Public |
| POST | /auth/register | Public |
| POST | /auth/login | Public |
| GET | /auth/me | Customer Bearer token |
| POST | /auth/logout | Customer Bearer token |
| GET/POST | /addresses | Customer Bearer token |
| DELETE | /addresses/{id} | Customer Bearer token |
| GET | /wishlist | Customer Bearer token |
| POST/DELETE | /wishlist/{product} | Customer Bearer token |
| POST | /checkout | Customer Bearer token, disabled by default |
| GET | /orders | Customer Bearer token |
| GET | /orders/{number} | Customer Bearer token |

## Cart quote example
```json
{"market":"EG","items":[{"variant_id":1,"quantity":2}],"coupon_code":null}
```

## Registration example
```json
{"name":"Customer Name","email":"customer@example.com","password":"replace-with-strong-password","password_confirmation":"replace-with-strong-password"}
```

## Checkout safeguards
- Public prices are fetched from active variant market prices; the client cannot submit unit prices.
- Checkout totals are recalculated on the server, and stock is reserved transactionally.
- Customer order queries are scoped to the authenticated store customer.
- Checkout is **disabled by default** (`STOREFRONT_CHECKOUT_ENABLED=false`) until a real shipping/tax policy, payment provider and webhook confirmation workflow are implemented and tested. Do not enable for live payments in this build.
- The frontend cart currently uses localStorage; integrate it by sending its items to `/cart/quote` and by handling `401` and validation errors.
- USD/EUR storefront pricing is not automatically supported. Create and verify the applicable markets and prices before enabling those currencies.

## Nuxt integration
Replace local Pinia product/category/brand data with HTTP responses; retain multilingual fields as required by the frontend. Product names/translations beyond the current schema need CMS extension and seeded content. Do not infer API readiness from the rendered frontend alone.

## Verification
Run PHP syntax checking on all PHP source files, install Composer dependencies and run `php artisan route:list --path=api/v1/store`, `php artisan migrate --pretend`, and project tests before staging release. No successful end-to-end database or payment integration test is claimed by this package.
