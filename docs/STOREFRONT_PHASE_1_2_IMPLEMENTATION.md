# KHAM Commerce — Phase 1 & 2 implementation and release checklist

Branch: `feature/storefront-phase1-phase2-20261118`
Change request date: 2026-11-17.
Canonical contract: `docs/openapi.json`.
**Do not deploy or enable live charges until the release checklist is satisfied.**

## Phase 1 — Implemented backend

- `GET /api/v1/storefront/markets`: active markets/currencies/default.
- `GET /api/v1/storefront/settings`: feature availability, checkout readiness and blockers.
- `GET /api/v1/storefront/shipping/destinations?market_id={id}`: active city list and wildcard `*` support.
- `GET /api/v1/storefront/products`: `market_id`, `search`, `brand`, `category`, `sort=newest|name|price_asc|price_desc` and pagination.
- `GET /api/v1/storefront/products/{id-or-slug}`: numeric ID or slug.
- Product list/detail exposes images, sellable variants, `sku`, `available_qty`, approved `review_count` and `average_rating`.
- Reviews GET supports product ID/slug.
- `POST /api/v1/storefront/checkout/quote`: server-priced, non-binding quote with `tax_calculation_method`, `estimated_total`, `checkout_ready`, `checkout_blockers`, and gated `final_payable_total`. No payment is collected by quote.
- Existing homepage, category, brand, ingredient, quiz, ritual, partner, newsletter and community contracts remain available.

## Phase 2 — Implemented backend code, integration testing pending

- `POST /api/v1/storefront/auth/register` and `login`: separate customer accounts and scoped 30-day Sanctum tokens, not CRM users.
- `GET /api/v1/storefront/auth/me` and `POST /logout`.
- Authenticated customer address CRUD, wishlist CRUD and delivered-purchase review submission awaiting CRM moderation.
- `GET /api/v1/storefront/orders` and `GET /api/v1/storefront/orders/{orderNumber}`: customer-owned orders only; shipment tracking details.
- `POST /api/v1/storefront/checkout`: **production-only** with independent disabled-by-default release gate, server-calculated price/shipping/approved tax, atomic idempotent order + inventory reservation + provider reservation, payment initiation, duplicate replay behavior and fail-closed uncertain-provider status.
- `POST /api/v1/storefront/payments/myfatoorah/live-webhook/{marketCode}`: HMAC Webhook V2, provider GET enquiry, invoice/payment ID/order/currency/amount verification, durable receipts, atomic idempotent settlement. Supported market codes: EG, AE, SA.
- Existing MyFatoorah sandbox-only endpoints remain separate and local-only.
- Order cancellation prevents stock release while a MyFatoorah payment is pending, initiated or uncertain.
- Configured shipping order totals use inclusive/exclusive market tax without double charging inclusive amounts.
- CRM refunds: pending request can be rejected by approver or cancelled by original requester with audit log; neither triggers an external refund.
- CRM ingredients and ritual quiz questions/options can be non-destructively archived.
- Finance list has validated `per_page` (max 100) and meta pagination.
- Finance/Super Admin permission migration grants finance.view/manage, refunds.approve, tax.approve. Assign actual Finance personnel separately.
- Canonical `docs/openapi.json` updated; YAML deprecated.

## Critical release blockers — NOT verified or cannot be done without operator setup

1. **All new migrations must be applied** on a backed-up MySQL development/staging database. Do not run `migrate:fresh` against existing data.
2. **Run complete tests and resolve failures.** Before this batch, 10 tests / 33 assertions passed locally. New account, catalog, checkout and webhook tests were subsequently committed but their results are not yet known. The CI workflow lints PHP and runs all tests.
3. **Configure actual shipping prices/cities and tax rules for Egypt, UAE and Saudi Arabia** in CRM, approve tax using a separate Finance/Admin user, verify correct tax-inclusive/exclusive business treatment. No fees or tax percentages are invented.
4. **Activate MyFatoorah merchant account(s)** and confirm supported currencies/payment methods, country routing, payout/refund procedures and live API access.
5. **Register HTTPS redirect and signed webhook V2 endpoints** with market-specific secrets. Validate real provider test vectors, accepted webhook retries and 200/202 behavior.
6. **Perform genuine provider sandbox end-to-end QA** (success, failed card, abandoned session, timeout, duplicate and reordered webhook, payment after manual cancellation request, concurrency, inventory rollback/reconciliation). Current production route is intentionally not usable in local/testing.
7. **Operational reconciliation and customer support** for uncertain provider initiation, stale/unpaid reservations, returns/refunds and stock release must be signed off. No automated reconciliation job or actual provider refund API has been released by this work.
8. **Frontends must integrate new APIs and error codes** and test against the correct CORS origins. This branch is the backend repository; Dashboard and Storefront frontends are separate.
9. **Security review** (access controls, rate limits, logging, webhook replay, credential rotation, database backups), deployment, monitoring and rollback plan are still required.

## Private environment settings (do not commit values)

```dotenv
APP_ENV=production
APP_KEY=base64:YOUR_GENERATED_KEY
APP_URL=https://your-api-host.example
CORS_ALLOWED_ORIGINS=https://your-customer-store.example,https://your-admin-dashboard.example
COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED=false
COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED=false
MYFATOORAH_LIVE_ENABLED=false
MYFATOORAH_LIVE_TOKEN_EG=
MYFATOORAH_LIVE_TOKEN_AE=
MYFATOORAH_LIVE_TOKEN_SA=
MYFATOORAH_WEBHOOK_SECRET_EG=
MYFATOORAH_WEBHOOK_SECRET_AE=
MYFATOORAH_WEBHOOK_SECRET_SA=
MYFATOORAH_LIVE_REDIRECT_URL=https://your-customer-store.example/payment-result
CRM_PASSWORD_RESET_FRONTEND_URL=https://your-admin-dashboard.example/reset-password
```

**Keep all three release flags false** until QA, provider onboarding and operational approval are complete.

## Local commands

```bat
git switch feature/storefront-phase1-phase2-20261118
git pull --ff-only
composer install
php artisan optimize:clear
php artisan migrate:status
php artisan test
php artisan route:list --path=api/v1/storefront
php scripts/local_openapi_full_coverage.php
```

After reviewing migration files and creating a database backup, run `php artisan migrate` on the intended environment. PHPUnit uses isolated SQLite in-memory tests and fake provider HTTP responses. The code is **not production-ready merely because unit/feature tests pass**.
