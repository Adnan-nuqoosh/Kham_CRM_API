# KHAM backend deployment and MyFatoorah sandbox runbook

**Status:** configuration, review and deployment preparation only. No production server, DNS, or actual MyFatoorah credentials are accessed by this repository change. The frontend is handled by the frontend developer.

## A. On local XAMPP — test API key, never live keys

Obtain a sandbox token from the official MyFatoorah demo portal: https://demo.myfatoorah.com/ (see MyFatoorah API Key docs).
Save it **only** in the local `.env`. Do not paste a key in ChatGPT, GitHub, screenshots or logs.

```dotenv
APP_ENV=local
APP_DEBUG=true
MYFATOORAH_SANDBOX_ENABLED=true
MYFATOORAH_SANDBOX_TOKEN=PASTE_YOUR_PRIVATE_TEST_TOKEN_HERE
MYFATOORAH_SANDBOX_HOSTED_CHECKOUT_ENABLED=true
MYFATOORAH_SANDBOX_REDIRECT_URL=https://YOUR-TEMPORARY-HTTPS-TEST-HOST.example/payment-result
MYFATOORAH_SANDBOX_WEBHOOK_ENABLED=true
MYFATOORAH_WEBHOOK_SECRET=YOUR_PRIVATE_SANDBOX_WEBHOOK_V2_SECRET
MYFATOORAH_LIVE_ENABLED=false
COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED=false
```

The HTTPS URL must be a working provider-accessible endpoint such as a correctly configured staging subdomain or narrowly controlled tunnel. `127.0.0.1` or a plain HTTP redirect is not sufficient for external provider callbacks.

```bat
php artisan optimize:clear
php artisan kham:myfatoorah-sandbox-check
php artisan route:list --path=api/v1/storefront
php artisan test
```

The diagnostics check private configuration without printing secrets or initiating a payment. If you already have a genuine **sandbox** PaymentId, you may run:

```bat
php artisan kham:myfatoorah-sandbox-check --payment-id=KNOWN_SANDBOX_PAYMENT_ID
```

This sends only read-only GET /v3/payments/{paymentId}; status is not changed in KHAM DB.

### Manual end-to-end sandbox test

1. Confirm an active shipping rule and approved tax policy for **the chosen market**, with actual agreed rates. Never invent region-specific taxes, delivery fees or provider fees.
2. Create an isolated test order in CRM under test data. Use authorized sandbox hosted-initiation endpoint. Never submit client-set payment amounts. Review the route permission with `route:list -v`.
3. Confirm a MyFatoorah **sandbox** payment URL, open it using documented test card credentials (not a real card), and observe the provider redirect.
4. Register a Webhook V2 `PAYMENT_STATUS_CHANGED` endpoint for the test/staging host. Enable signature with the matching secret and use the sandbox webhook route; confirm invoice/reference/amount/currency and provider-side GET payment enquiry.
5. Verify exactly one payment and one paid transition; replay the identical webhook and check it is idempotent.
6. Repeat with declined payment, abandoned checkout, duplicate idempotency key, unknown initiation result, invalid signature, wrong amount/currency, invalid market, and late webhook.
7. Document results with redacted invoice numbers and timestamps. Do not automatically release stock on uncertain payment attempts.

The repo includes both **sandbox-only local CRM hosted payment** and **production-only customer checkout**. The latter stays inaccessible on local/testing. A production merchant sandbox/staging workflow requires a deliberate separate release and security review; never set `APP_ENV=production` merely to bypass environment checks locally.

## B. Production deployment — PREPARED, NOT EXECUTED

Before changing production, provision a **separate staging environment** with an independent DB and separate credentials. Verify TLS and DNS first.

1. Back up the real MySQL database, user uploads and current release. Validate you can restore them.
2. Deploy reviewed source to a release directory using the reviewed commit from PR #7. The web server **document root must point to Laravel `public/`**, not repository root. Ensure `.env`, logs and storage are inaccessible from the web.
3. Use supported PHP 8.3+ with `bcmath`, `mbstring`, `intl`, `pdo_mysql`, `curl`, `openssl`, `fileinfo` and XML extensions. Harden filesystem ownership and write permissions for `storage` and `bootstrap/cache`. Do not use chmod 777.
4. Configure private server `.env` values: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, strong `APP_KEY`, valid DB and SMTP credentials, restrictive `CORS_ALLOWED_ORIGINS`, secure mail/reset URL, rate limits, backups.
5. Keep `COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED=false` and `MYFATOORAH_LIVE_ENABLED=false`. Never put a test token in `MYFATOORAH_LIVE_TOKEN_*`. Sandbox and production keys must be strictly separated.
6. Execute on staging first, then production after explicit approval:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan migrate --pretend
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan kham:release-audit
php artisan kham:payment-reconciliation-report --older-than=30 --limit=50
php artisan route:list --path=api/v1/storefront
```

Do not run `migrate:fresh`, `db:wipe`, `key:generate` on an existing deployed app, or run the SQLite PHPUnit suite against the live database. Check and preserve existing APP_KEY, encryption, sessions, database backups and token access.

7. Confirm firewall, TLS certificate auto-renewal, `/up` health endpoint, Cloudflare or reverse proxy trust settings, log rotation, monitoring, worker/queue supervisor where configured, cron (Laravel scheduler) and backups with restore drill.
8. Review private merchant settings, signature vectors, webhook retry timing, payment method support and order/payment reconciliation operational procedure before enabling any production payment flags.
9. Run controlled smoke tests from an allowed staging origin and collect HTTP status, not private payloads. Document rollback. Promote through a reviewed pull request only after tests and code review.

### Security controls reviewed in code

- Customer accounts use separate model and Sanctum token scopes; CRM routes reject customer tokens for CRM identity.
- Customer orders, addresses and wishlist are scoped to authenticated customer ID.
- Failed/unknown provider initiation cannot be automatically retried with a new key.
- Signed Webhook V2 is verified and payment amount, currency, invoice, payment ID and order reference are reconciled against provider GET details before atomic settlement.
- Production checkout has an independent disabled-by-default release gate.
- Shipping/tax come from server market rules; client price and tax overrides are prohibited.
- Payment secrets and signing secrets are read from private config and not returned by preflight command.

### Still requires external verification

- Real MyFatoorah test token and webhook secret (not provided to source code).
- Real sandbox payment attempt and provider callback using HTTPS.
- Actual EG/AE/SA merchant support, shipping fees and approved tax policies.
- Load/concurrency testing against staging MySQL, failed payment stock release/reconciliation and provider refunds.
- Production host credentials, DNS/TLS validation, backups, real deployment authorization and post-deploy checks.

**Do not mark production deployment complete merely because CI tests pass.**


## Automated verification snapshot

- CI source lint and PHPUnit: **28 tests / 102 assertions passed** on 2026-11-18.
- API operation contract parity: **143/143**, missing 0, extra 0.
- CI exercises a mocked provider; this is **not** a real MyFatoorah sandbox purchase and not a substitute for external webhook delivery testing.
- Read-only payment triage command: `php artisan kham:payment-reconciliation-report --older-than=30 --limit=50`. It never retries, captures, refunds, or changes inventory.
- Read-only release checks: `php artisan kham:release-audit`. On local XAMPP it will flag intentionally insecure development settings such as `APP_DEBUG=true` and HTTP APP_URL; such warnings are expected locally.


## Security audit closeout — 2026-11-18

A Composer audit originally found 10 dependency advisories affecting 6 packages. The locked dependencies were upgraded on this feature branch and verified by a fresh GitHub Actions run (#48):
- `composer audit --locked --no-interaction`: **No security vulnerability advisories found**
- PHP syntax lint: **PASS**
- PHPUnit: **29 tests / 118 assertions PASS**
- OpenAPI: **143 operations accounted for; 0 missing, 0 extra**

The one-time workflow which had temporary branch write permission was removed after `composer.lock` was updated. Regular CI continues to enforce Composer audit on every push and PR.

Actual provider payment, approved market shipping/tax policy, external security penetration test, staging load test and real server rollout are still unverified. Keep live release flags off until those operational gates are satisfied.

## Storefront shopper sandbox hosted checkout (PR #9)

Separate from CRM sandbox hosted checkout and from production-only `POST /api/v1/storefront/checkout`. Shopper access requires a customer Sanctum token with the `storefront` ability. Never expose the MyFatoorah sandbox token to the browser.

- `POST /api/v1/storefront/checkout/sandbox`: accepts exactly the same server-priced checkout payload as production checkout; returns `STOREFRONT_SANDBOX_CHECKOUT_CREATED` with a hosted sandbox `payment_url` and pending `payment_status`. Reusing the same idempotency key is safe; an uncertain provider outcome must not be retried using a fresh key.
- `GET /api/v1/storefront/settings?market_id=N`: `data.features.sandbox_checkout_enabled` and `data.sandbox_checkout.{ready,reason_code,blockers,tax_status,is_final_payable_total}` are for the **selected market** and are separate from live checkout readiness.
- `POST /api/v1/storefront/payments/myfatoorah/sandbox-webhook`: signed provider-only callback (no customer authentication); verifies signature, stored sandbox reservation, provider payment enquiry, invoice, order, amount and currency before exactly-once settlement. Must be configured at a public HTTPS staging URL in MyFatoorah demo portal, not localhost.

In **isolated local or staging only** (never enable sandbox flags in production):

```dotenv
APP_ENV=staging
COMMERCE_STOREFRONT_SANDBOX_CHECKOUT_ENABLED=true
MYFATOORAH_SANDBOX_ENABLED=true
MYFATOORAH_SANDBOX_WEBHOOK_ENABLED=true
MYFATOORAH_SANDBOX_TOKEN=<private-demo-credential>
MYFATOORAH_WEBHOOK_SECRET=<private-demo-v2-secret>
MYFATOORAH_SANDBOX_REDIRECT_URL=https://your-isolated-staging-domain.example/payment-result
COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED=true
MYFATOORAH_LIVE_ENABLED=false
COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED=false
```

Use an independent staging DB, explicit finance-approved test tax policy, market shipping rules, available inventory and a valid demo merchant account. On local XAMPP without a public HTTPS callback, leave sandbox flags OFF and use automated mocked-provider tests instead. The storefront sandbox checkout is never available in production even if a flag is accidentally set. The server's `APP_ENV` must reflect the deployment; never mislabel production as staging to bypass a gate. 

This is an API implementation and CI test, **not** evidence of a real merchant sandbox transaction, externally delivered webhook or deployed Next.js checkout page.
