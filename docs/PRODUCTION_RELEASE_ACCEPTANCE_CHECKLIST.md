# KHAM Commerce — production release acceptance checklist

**Scope:** Backend delivery and operator sign-off. Completing GitHub code is **not** the same as activating a real merchant or testing a live deployment. Keep MyFatoorah live, configured shipping orders and public coupons **OFF** until all release gates are verified for each market.

## 1. Merchant configuration — EG / AE / SA

| Market | Currency | Merchant live token | Webhook V2 secret | Merchant-enabled methods | Provider transaction verified | Status |
|---|---|---|---|---|---|---|
| EG | EGP | Operator check | Operator check | Operator check | Operator check | PENDING |
| AE | AED | Operator check | Operator check | Operator check | Operator check | PENDING |
| SA | SAR | Operator check | Operator check | Operator check | Operator check | PENDING |

- Obtain merchant-issued credentials through the authorized payment provider account; **never commit, paste or print API tokens**. Configure `MYFATOORAH_LIVE_TOKEN_EG`, `_AE`, `_SA` and matching `MYFATOORAH_WEBHOOK_SECRET_*` only in private environment storage.
- Confirm with the provider which methods (CARD, APPLE_PAY, GOOGLE_PAY, KNET) are enabled **per merchant and market**. The code accepting a method string does not mean a provider account supports it.
- Configure server-controlled `MYFATOORAH_LIVE_REDIRECT_URL` as an existing HTTPS customer return page.
- Webhook URL: `POST /api/v1/storefront/payments/myfatoorah/live-webhook/{marketCode}`. Keep it behind HTTPS, verify signature and perform provider enquiry; never mark an order paid based on the browser redirect.
- If credentials are missing, leave flags OFF. Never use production merchants to run uncontrolled or automated real-money tests.

## 2. Business approval and email delivery

| Check | Required evidence | Status |
|---|---|---|
| Market-specific tax rates and inclusive/exclusive method | Authorized finance approval in CRM, `is_approved=true` with approver/time | PENDING |
| Shipping rules and supported destinations | Approved city/fallback rule and shipping fees for each launched market | PENDING |
| Warehouse stock, pricing, currencies | Known market and warehouse mapping, validated catalog and stock | PENDING |
| CRM password reset | Existing HTTPS `CRM_PASSWORD_RESET_FRONTEND_URL` and delivered email | PENDING |
| Customer password reset | Existing HTTPS `STOREFRONT_PASSWORD_RESET_FRONTEND_URL` and delivered email | PENDING |
| Mail transport | Real SMTP/provider credentials, sender domain authentication and mailbox delivery check | PENDING |
| CORS / HTTPS | Specific trusted site origins, valid TLS and no wildcard CRM API origins | PENDING |

Use `php artisan kham:release-audit` for **safe, read-only pre-launch checks** (payments remain OFF), then `php artisan kham:release-audit --launch-ready --strict` **only during controlled release rehearsal** when launch configuration is intentionally applied. The strict mode is read-only and fails if checks are not met; it does not prove provider credentials, permitted payment methods or SMTP actually work. **Do not turn on production gates merely to pass the command.**

## 3. Staging-only end-to-end acceptance

Use a separate staging database, test products/stock and a non-production merchant/simulator. Record evidence for:

- Customer register/login and auth token isolation; profile edit, password change, forgot/reset (real mailbox)
- Country/market selection and correct EGP/AED/SAR pricing and destination policy
- Public quote, coupon eligibility (if enabled), discounts, tax, shipping and immutable server pricing
- Test checkout creates exactly one order, pending mock payment and stock reservation; CRM `GET /api/v1/orders?search=...` shows that order
- Reused idempotency key returns the original order/payment session; changed payload with same key rejects
- Provider success: verify signed event **and** matching provider enquiry, then update payment once
- Provider failure, timeout/unknown, repeated or invalid webhooks leave order/payment safely pending; resolve via read-only reconciliation report
- Negative access tests: customer cannot read another customer's order and CRM staff cannot impersonate storefront customer
- Refund maker-checker approval/rejection, cancellation and audit trail; approval alone **does not issue money back**

Automated feature tests cover many of these flows using a **fake provider and isolated test DB**, not a real merchant account. Do not certify the production provider from test mocks.

## 4. Frontend handoff

The customer-facing Next.js website integrates `/api/v1/storefront/auth/*`, `/api/v1/storefront/checkout/quote`, authenticated `/api/v1/storefront/checkout` and `/api/v1/storefront/orders/*`. The KHAM dashboard integrates `/api/v1/orders`, payments, finance and refund endpoints. See `docs/openapi.json` and `integrations/FRONTEND_HANDOFF.md`. These applications are managed by separate frontend developers; their actual code repositories were **not connected** to this PR.

## 5. Review, staging deploy and go/no-go

1. Review PR #8, check latest PHP CI status, perform security review and merge only through normal project approval.
2. Create a database backup and test restore, snapshot environment and validate PHP/Composer and permissions.
3. On the isolated staging environment: `composer install --no-dev --prefer-dist --optimize-autoloader`; `php artisan migrate --force`; `php artisan config:cache`; `php artisan route:cache`. **Never run `migrate:fresh` or `db:seed` on a live database.**
4. Run `php artisan kham:release-audit` and `php artisan kham:payment-reconciliation-report --older-than=30`; inspect output without leaking credentials.
5. Run the explicit per-market staging acceptance matrix and log transaction/order evidence for manual sign-off.
6. Obtain merchant, finance, QA and operations approval before any production feature-flag activation.
7. If any launch gate fails, keep `COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED=false` and `MYFATOORAH_LIVE_ENABLED=false`.

## Remaining external dependencies (not completable from GitHub)

- Authorized merchant portal credentials and provider-enabled payment methods
- Finance-approved tax rates, shipping fees and fulfillment coverage
- SMTP/domain identity and actual delivered password reset test
- Real frontend repositories or deployed frontend integrations
- Access to staging server/database and real staging payment/simulator for acceptance testing
- PR merge authorization, backups, release window and production deployment approval

**No credentials, market legal/tax values, shipping fees, payment success or staging deployments are inferred from code.**
