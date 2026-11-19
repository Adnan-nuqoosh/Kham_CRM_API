# KHAM API operator and dashboard integration guide

## Source of truth

`docs/openapi.json` is the authoritative route/schema specification. `docs/KHAM_Commerce_API_OpenAPI.yaml` is legacy and deprecated. Maintain separate documentation for admin `POST /api/v1/checkout/quote` (supports coupons) versus storefront `POST /api/v1/storefront/checkout/quote` (currently rejects coupon_code; not final payable).

## CRM deployment prerequisites

- Generate `APP_KEY` on a **new local installation only**; do not rotate an existing production key.
- Configure local `ADMIN_EMAIL` and a unique strong `ADMIN_PASSWORD` before initial seeding. Never put passwords in Git or paste them into support tickets.
- Configure `CORS_ALLOWED_ORIGINS` with the exact dashboard origin (including scheme and port), e.g. developer `http://localhost:3001`, when appropriate. Production origins should use HTTPS; do not use wildcard origins for admin APIs.
- `CRM_PASSWORD_RESET_FRONTEND_URL` must be a real HTTPS dashboard reset page. The email notification links using `?token=...&email=...`; the frontend submits these plus `password` and `password_confirmation` to `POST /api/v1/auth/reset-password`. When missing, forgot-password returns `AUTH_RESET_UNAVAILABLE` (503).
- Customer reset is *separate*: `STOREFRONT_PASSWORD_RESET_FRONTEND_URL` is a real HTTPS storefront reset page; it uses the same query parameters, `POST /api/v1/storefront/auth/reset-password`, and an isolated `storefront_password_reset_tokens` table. Keep SMTP valid and protect logs against token leakage. This feature fails closed (503) without configured URL.

## RBAC permission matrix

These are route permissions rather than a guarantee about the names of staff roles in a specific live database. `Super Admin` receives the permissions in `DatabaseSeeder` on clean installations. Existing migration `2026_11_18_030000_assign_finance_approval_permissions.php` grants `finance.view`, `finance.manage`, `refunds.approve`, `tax.approve` to Super Admin and creates a `Finance Approver` role. It **never assigns users** automatically. Do not run production seeders to adjust staff permissions.

| Function | Required permission | Important rule |
| --- | --- | --- |
| Dashboard widgets | `dashboard.view` | CRM user auth via Sanctum |
| Product browse & CMS (homepage, ingredients, rituals, quiz, reviews) | `products.view` | CMS reuses catalog permissions by design |
| Product/CMS mutations | `products.manage` | Nested price/stock/cost changes also check dedicated privileges |
| Market prices | `pricing.manage` | Never accept storefront-supplied selling prices |
| Inventory | `inventory.view` / `inventory.manage` | Warehouse stock movements tracked |
| Customers + partner applications + newsletter | `customers.view` / `customers.manage` | CRM-only, explicit permission |
| Orders + refund creation/cancel | `orders.view` / `orders.manage` | Original requester alone can cancel pending refund |
| Refund approval/rejection | `refunds.approve` | Approver cannot also be original requester |
| Finance reports / CSV | `finance.view` | Currency-specific results |
| Finance expenses, item cost | `finance.manage` | No cross-currency profit claims |
| Market tax approval | `tax.approve` | Requires `confirm=true`; approval does not enable payments |
| Shipping/settings | `settings.manage` | Shipping rule city and market immutable |
| Coupon catalog | `coupons.manage` | CRM-only management; public coupons not enabled |

`GET /api/v1/auth/me` returns the logged-in CRM user with `roles.permissions`. The dashboard must derive sidebar and action visibility from these permissions and must not default to showing everything when permissions are missing. Backend permission middleware remains authoritative.

## Shipping, tax, finance and refund contracts

- Shipping rules are keyed by immutable market and normalized city. City `*` is a fallback for destinations not matched by a more specific active rule. To switch cities or markets, create a new rule and disable the old one.
- Tax rule updates reset `is_approved=false`, `approved_at=null`, `approved_by=null`. `GET /api/v1/market-tax-rules` includes these fields. An explicit finance-authorized approval alone does **not** enable final customer payable totals.
- Expenses support `page` and `per_page` (1–100), plus market/date/category filters. API includes Laravel paginator `data` and stable pagination in `meta`.
- Finance `GET /api/v1/finance/report/export.csv` returns `text/csv; charset=UTF-8` as a file, not JSON. Do not apply JSON deserialization to that response.
- Refund statuses include `pending_approval`, `approved_awaiting_provider`, `rejected`, `cancelled`. Approval **does not transfer funds**. Provider reconciliation is a separate operational process. Rejection requires a reason (5–2000 characters) and a different approver. The pending original requester can cancel.
- Errors use the standard envelope `{success,code,message,data,meta,errors}`. Validation errors return HTTP 422 with `errors` keyed by input field. Frontends should parse stable `code` values and render `errors[field]`, not solely free-text `message`.

## Storefront coupons (implemented, gated OFF by default)

- `COMMERCE_STOREFRONT_COUPONS_ENABLED=false` is the safe default. The public `/storefront/settings` response publishes `features.coupons_enabled` only if the flag is explicitly set. Enabling this flag is a business/release decision; do not enable on production before end-to-end coupon, tax, shipping and payment QA.
- When enabled, `coupon_code` is optional in the public quote and authenticated production checkout. Both use backend prices, the discounted net subtotal for shipping/free-shipping thresholds, and the same net subtotal for tax preview.
- Coupon redemptions are inserted **inside the order transaction**, holding a coupon row lock. `max_uses` and `max_uses_per_customer` are counted from non-cancelled, non-returned orders. Cancelled/returned orders release eligibility in subsequent checks. Existing idempotency keys prevent the same checkout retry creating a second order/redemption.
- Percentage coupons can apply across markets only when no restricted market list is configured; their discount is capped at the subtotal. Fixed-amount coupons **require** `rules: { "market_ids": [<exactly one numeric market ID>] }`; using one fixed EGP amount in AED/SAR markets would be wrong. Rules other than `market_ids` are deliberately rejected instead of silently ignored.
- `max_uses_per_customer` coupons require a real storefront account. For public quote preview, include a valid customer bearer token or the preview returns field-level 422. No coupon is reserved during a preview, and eligibility may change before a new order is created.
- The existing CRM coupon manager's `rules` JSON must be reviewed before enabling. Merchant eligibility policy (returns, per-product targeting, partial refunds) requires explicit product/legal decisions. Manual `POST /orders` uses the same per-customer usage enforcement when a coupon is supplied.
- Coupons never bypass the live payment, approved tax, or shipping release gates.

## Staging MyFatoorah test-only routes

- `APP_ENV=local` and `COMMERCE_STOREFRONT_TEST_CHECKOUT_ENABLED=true` are required for local mock guest checkout; **never configure `APP_ENV=local` on the public production CRM**.
- Mock checkout `POST /api/v1/storefront/checkout/test-order` requires a unique `idempotency_key` (16–128 allowed characters), valid market, variant quantities, customer/contact details, shipping address, and `payment_method=mock_fatoora`. It reserves **real staging stock** and creates staging orders: use an isolated staging DB and secure local network access only.
- Sandbox hosted payment operations require `APP_ENV=local/testing` and the appropriate `MYFATOORAH_SANDBOX_ENABLED`, `MYFATOORAH_SANDBOX_HOSTED_CHECKOUT_ENABLED`, token and `MYFATOORAH_SANDBOX_REDIRECT_URL` (HTTPS) config. Webhook simulation has its own opt-in flag and secret. Provider timeout is an *uncertain* state, never a reason for blind automatic resubmission.
- Real checkout remains OFF unless `COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED`, `MYFATOORAH_LIVE_ENABLED`, `COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED`, merchant tokens/webhook secrets, HTTPS redirect, approved tax policy and active market shipping have all passed market-specific release audit.

## Frontend responsibilities (not backend tasks)

- The dashboard owns Reject/Cancel refund buttons; backend routes exist.
- The storefront owns the UI for profile editing, password change, forgot/reset forms and dynamic market selection.
- `GET /api/v1/storefront/markets` plus `GET /api/v1/storefront/settings?market_id=...` are available; **removing a legacy `NEXT_PUBLIC_STORE_MARKET_IDS` frontend flag is not a backend defect**.
- Product/coupon UI must never suggest live checkout works until API readiness flags allow it.

## Explicitly out of scope

Per the updated request, **do not work on ingredient/quiz archive semantics documentation** in this change set. Optional guest order lookup remains disabled for privacy because current production checkout is authenticated/account-only.
