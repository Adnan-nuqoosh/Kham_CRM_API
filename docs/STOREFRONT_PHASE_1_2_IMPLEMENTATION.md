# Storefront Phase 1 / Phase 2 implementation status

Branch: `feature/storefront-phase1-phase2-20261118`
Original requirements: 2026-11-17 storefront/backend change request.

## Phase 1: implemented, requires QA

- `GET /api/v1/storefront/markets`: active market IDs, codes, currency and default marker.
- `GET /api/v1/storefront/settings`: feature flags and fail-closed checkout readiness.
- `GET /api/v1/storefront/shipping/destinations?market_id={id}`: active shippable country, named city keys and `other_cities_supported` (wildcard `*`).
- `GET /api/v1/storefront/products?market_id={id}&brand={slug}&category={slug}&sort=newest|name|price_asc|price_desc`: adds brand/category and ordering controls; existing search and pagination remain.
- `GET /api/v1/storefront/products/{id-or-slug}?market_id={id}`: product detail accepts either identifier.
- Storefront variant entries now expose `sku` and `available_qty` (the fulfillment warehouse's available stock).
- `POST /api/v1/storefront/checkout/quote` now returns `checkout_ready:false` and machine-readable `checkout_blockers`. It remains explicitly non-binding; no checkout/payment can be completed from quote alone.

The existing public catalog/category/brand/ritual/homepage APIs are unchanged.

## Phase 2: implementation progress (NOT production-ready)

- Added regional MyFatoorah V3 live API client `MyFatoorahLiveClient` for EG/AE/SA with explicit config gate and market-specific server credentials. It is **not wired to public routes**. Enabling the client alone is insufficient for accepting payments.
- Quote now offers `estimated_total` only when a tax amount is available, and explicitly distinguishes inclusive vs exclusive tax. Quote remains provisional.
- Configured shipping order service was corrected to avoid adding inclusive tax twice in `grand_total`. Regression tests and integration tests are mandatory before release.
- Added market-tax calculation tests for inclusive/exclusive and shipping-tax policy, plus live client safety tests. These are committed but must be run locally.
- No production checkout or payment-capture route was added. Existing sandbox behavior remains isolated.

## Phase 2: not production-ready — mandatory remaining work

**Do not activate real checkout or accept payments yet.**

1. Design and implement `POST /api/v1/storefront/checkout` with authenticated or securely owned guest order, strict server-side price and stock calculation, idempotency and atomic stock reservation, no user-supplied amounts, and recovery from interrupted payment initiation.
2. Finalize the payable policy after regression testing the implemented inclusive/exclusive distinction, shipping, fees and discounts. Confirm rounding, country/currency, and MyFatoorah account support for EG/AE/SA. Current `MarketTaxQuoteService` returns `is_final_payable_total=false` even when tax is approved; do not bypass this.
3. Add country-specific live MyFatoorah v3 API adapters. The existing `MyFatoorahV3Client` and webhook implementation are *sandbox-only* and must not be exposed as live routes.
4. Process signed provider webhook V2 with authentic signature validation, idempotent event receipt, provider-side GET payment verification, stored order/invoice/amount/currency checks and atomic payment/stock state transitions. Never mark an order paid solely on browser redirect.
5. Implement an authenticated customer-account or verified guest ownership model before `GET /storefront/orders` and get-by-order-number. Public order-number-only lookup is not safe.
6. Handle expiration, failure, refund, cancellation and stock release under repeated/reordered webhooks. Add automated concurrency and retry tests.
7. Publish OpenAPI contracts and run Laravel feature/integration tests against MySQL and the sandbox. Obtain live provider keys, HTTPS callback/redirect, registered webhook, deployment environment settings and operator approval before a real transaction.

## Suggested QA

```bash
php artisan optimize:clear
php artisan route:list --path=api/v1/storefront
php artisan test
curl -H "Accept: application/json" "http://127.0.0.1:8000/api/v1/storefront/markets"
curl -H "Accept: application/json" "http://127.0.0.1:8000/api/v1/storefront/settings"
curl -H "Accept: application/json" "http://127.0.0.1:8000/api/v1/storefront/shipping/destinations?market_id=1"
curl -H "Accept: application/json" "http://127.0.0.1:8000/api/v1/storefront/products?market_id=1&sort=price_asc"
```

Run requests with valid seeded market IDs. Verify no production checkout/payment endpoint is enabled until all gates pass.

## Updated local QA commands (after pull)

```bat
git pull --ff-only
php artisan optimize:clear
php artisan test --filter=StorefrontBootstrapTest
php artisan test --filter=MarketTaxCalculationTest
php artisan test --filter=MyFatoorahLiveClientSafetyTest
php artisan test
```

Do not run `migrate:fresh` on the existing development MySQL database.
The SQLite `:memory:` PHPUnit configuration is isolated from the developer's MySQL data.
