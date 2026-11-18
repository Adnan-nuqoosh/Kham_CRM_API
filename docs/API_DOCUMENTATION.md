# KHAM Commerce OS — API documentation

**Canonical OpenAPI 3.0.3 contract: `docs/openapi.json`.** Import this JSON into Apidog, Swagger UI/Editor or client generators. `docs/openapi.yaml` is deprecated and may omit newly implemented routes. Continue checking actual route/controller behavior: OpenAPI route coverage alone is not proof of production readiness. Existing `docs/APIDOG_SETUP.md`, `docs/APIDOG_TESTING_GUIDE.md`, and `docs/API_ENDPOINTS.md` remain available.

## Local setup
- Base URL: `http://localhost` (adjust if your XAMPP virtual host uses another URL).
- All documented routes start with `/api/v1`.
- CRM routes use `Authorization: Bearer <SANCTUM_TOKEN>` and require the permissions noted in each operation. Never commit real tokens.
- Public routes do not require a token; write endpoints have throttling.
- Response envelope typically includes `success`, `code`, `message`, `data`, `meta`, `errors`.
- HTTP 401: missing authentication; 403: insufficient permissions; 422: invalid input; 429: throttled.

## Sample safe requests
```bash
curl -H "Accept: application/json" "http://localhost/api/v1/storefront/homepage?locale=ar"
```

```json
{
  "contact_name": "Example Person",
  "email": "example@example.invalid",
  "company_name": "Example Company",
  "partner_type": "retailer",
  "message": "Interested in partnership"
}
```
POST this JSON to `/api/v1/storefront/partners/apply`. The public response deliberately omits applicant PII.

## Contract checks
Run `php scripts/local_openapi_contract_check.php` after `git pull`. This is a **static route/method coverage smoke check**, not a full YAML/OpenAPI semantic validator. The script does not connect to or modify the database. Use an OpenAPI 3 validator in your API documentation tool for schema validation.

## Next documentation phases
Document remaining CRM, catalog, inventory, checkout, shipping, finance, refunds, quiz, reviews, ingredients, rituals, brands, and authentication endpoints; add complete response examples and error schemas; validate all paths against the live route list. Do not publish internal-only payment test routes as production-ready.

## Full route inventory (Phase 2)
The OpenAPI file now inventories all current Laravel API route/method operations. Entries with `x-documentation-status: route-inventory-only` are **not fully specified contracts**: request bodies, exact response status codes, and endpoint-specific response schemas still need review. Never infer production readiness from inclusion, particularly local/sandbox payment routes.

Run:
```bat
php -l scripts/local_openapi_full_coverage.php
php scripts/local_openapi_full_coverage.php
```
The checker compares Laravel's registered `api/v1` methods (excluding automatic HEAD) against the OpenAPI paths. A single Laravel route may register multiple methods (e.g. PUT|PATCH), so the operation count can exceed the route count. This is a route inventory check, not a full OpenAPI schema validator. No migrations or database writes are performed.

## Storefront Phase 1 / Phase 2 additions (November 18, 2026)

See `docs/STOREFRONT_PHASE_1_2_IMPLEMENTATION.md` for detailed safety gates and pending operator work.

- Public: `/storefront/markets`, `/storefront/settings`, `/storefront/shipping/destinations?market_id={id}`, catalog filters and slug lookup.
- Customer authentication: `POST /storefront/auth/register`, `POST /storefront/auth/login`, `GET /storefront/auth/me`, `POST /storefront/auth/logout`.
- Customer-scoped bearer-token endpoints: `GET /storefront/orders`, `GET /storefront/orders/{orderNumber}`, address CRUD, wishlist CRUD, and customer review submission. CRM admin Sanctum tokens are NOT customer tokens.
- Production-only: `POST /storefront/checkout` initiates an idempotent hosted payment only when all configured release gates are ready. No client-controlled prices, shipping or tax are accepted.
- Signed Webhook V2: `POST /storefront/payments/myfatoorah/live-webhook/{marketCode}`, where `marketCode` is EG, AE or SA. Must validate HMAC, stored invoice/order, provider-side payment enquiry, amount, currency, and atomic settlement.
- CRM: refund reject/cancel (no provider refund), ingredient/ritual quiz archival, finance expense `per_page` maximum 100.

### Tax and shipping

Shipping rules' `market_id` and `city` are immutable after creation; add a new rule if market/city needs to change. City `*` is a fallback for other cities within the selected market's country, not an international shipping wildcard. A specific active city rule takes priority. Approved market tax may be `inclusive` (tax already included in price) or `exclusive` (tax added). Never charge inclusive tax twice. `tax_shipping` determines whether shipping enters the taxable base. No market tax rate or shipping fee is assumed by the code: authorized operators must configure actual business rules.

### Required environment and deployment steps

- `APP_KEY`, admin credentials, app URL, CORS origins, HTTPS and password reset frontend URL must be configured privately per deployment.
- `COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED` and `COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED` default false.
- `MYFATOORAH_LIVE_ENABLED` defaults false; keep it off until integration tests and merchant account onboarding are completed.
- `MYFATOORAH_LIVE_TOKEN_EG/AE/SA` and `MYFATOORAH_WEBHOOK_SECRET_EG/AE/SA` are private regional secrets. Never commit them.
- `MYFATOORAH_LIVE_REDIRECT_URL` must be a valid HTTPS URL.
- Verify market currencies and supported merchant payment methods with MyFatoorah; a syntactically valid token does NOT establish a production-ready merchant account.
- Do not run `migrate:fresh` on an existing database. Take a backup, review new migrations, then run `php artisan migrate` and local tests.
- Ensure callback URL registration, firewall/rate limits, monitoring, provider reconciliation, refund support and operational runbooks before enabling live charges.

### Permissions matrix (current)

- `products.view/manage`: catalog, homepage, ingredients, rituals, quiz, review moderation. Dedicated CMS permissions remain a future optional RBAC refactor to avoid breaking existing dashboard roles.
- `customers.view/manage`: customer records, partner applications, newsletter subscriber administration.
- `orders.view/manage`: orders, refund submission, requester cancellation.
- `refunds.approve`: refund approval/rejection and audit. Requester may not approve or reject own refund.
- `tax.approve`: finance tax policy approval. `settings.manage`: tax policy changes and shipping rules.
- `finance.view/manage`: finance reports, expenses and order-item costs.
- `/auth/me` currently includes `roles.permissions` for CRM dashboard visibility gating.

### Verification

```bat
git pull --ff-only
php artisan optimize:clear
php artisan migrate:status
php artisan test
php artisan route:list --path=api/v1/storefront
php scripts/local_openapi_full_coverage.php
```

The PHPUnit suite uses isolated SQLite `:memory:`. New MyFatoorah tests use mocked HTTP responses, NEVER real charges. A green test suite is necessary but not sufficient for payment go-live.
