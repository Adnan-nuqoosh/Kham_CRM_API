# KHAM CRM API — MyFatoorah Integration Progress

Updated: 2026-11-09
Branch: `feature/myfatoorah-v3-webhook-verification-20261108`
Environment: Windows XAMPP, Laravel 12, local MySQL `kham_crm_api`

## Verified by developer (local runs)

- Signed webhook HTTP regression: **20/20 checks passed**, database fixtures and Egypt stock restored.
- Hosted checkout mocked regression: **9/9 checks passed**, database fixtures and Egypt stock restored.
- Hosted initiation → signed webhook → atomic paid settlement **end-to-end regression: 22/22 checks passed** (developer local run); fixtures and Egypt stock restored.
- Routes registered: `POST /api/v1/storefront/payments/myfatoorah/local-webhook` and authenticated `POST /api/v1/orders/{order}/payments/myfatoorah/sandbox-hosted`.
- PHP lint for hosted controller and hosted regression passed.
- All provider calls in these tests are mocked. **No live sandbox payment was confirmed.**

## Current implementation

- Local/testing-only MyFatoorah V3 client, hosted payment creation and read-only provider enquiry.
- CRM-authenticated, `orders.manage` guarded hosted checkout; server-controlled amount and redirect, invoice ID stored on a pending payment attempt.
- Signed webhook verifies event, reconciles provider invoice/payment/order/amount/currency, and atomically stores receipt plus paid transition.
- Idempotent callback, cancelled-order guard, stock unaffected.
- Application-level guard rejects reuse of the same MyFatoorah invoice across orders. **Local database unique index migration applied successfully** (`payments_provider_transaction_reference_unique`), and hosted 9/9 plus end-to-end 22/22 checks passed afterward. Live VPS database has not been migrated.
- Hosted checkout is disabled by default: `MYFATOORAH_SANDBOX_HOSTED_CHECKOUT_ENABLED=false`. Sandbox gateway disabled by default.
- Local guest checkout remains a separate **mock_fatoora** flow; it is not connected to the hosted gateway.

## Next milestones (not yet verified)

1. **COMPLETE (mocked):** End-to-end hosted initiation → signed webhook → paid order/payment/receipt regression at `scripts/local_myfatoorah_end_to_end_regression.php`; **22/22 local checks passed**.
2. **PARTIAL:** Database-enforced provider/reference uniqueness migration applied and local regressions passed. **NEXT:** Test database collision behavior explicitly; review initiation idempotency and concurrency, crash recovery if provider accepts payment but local DB write fails; avoid holding DB row locks across network calls.
3. Confirm provider V3 request/response contract and redirect/external identifier requirements against current sandbox documentation, then test with real sandbox credentials securely (never commit secrets).
4. Decide production architecture: public checkout ownership/authentication, market/currency/tax readiness, reliable webhook processing, retry/reconciliation, logs and operational controls. **No production enablement until these are complete.**

## Safe local commands

```bat
git pull origin feature/myfatoorah-v3-webhook-verification-20261108
php scripts/local_myfatoorah_http_webhook_regression.php
php scripts/local_myfatoorah_hosted_checkout_regression.php
php -l scripts/local_myfatoorah_end_to_end_regression.php
php scripts/local_myfatoorah_end_to_end_regression.php
```

**Safety:** Never run `migrate:fresh` or destructive reset on `kham_crm_api`. Regression fixtures use transaction rollback. Never store real API tokens or webhook secrets in Git.

### Local verification — 2026-11-09
- User ran additive unique-index migration successfully on local XAMPP database only.
- Hosted checkout regression **11/11 PASS**, including direct database duplicate rejection and same-reference/different-provider acceptance.
- End-to-end webhook regression **22/22 PASS**; prior HTTP webhook regression **20/20 PASS** (53 total checks across suites; latest run covered hosted and end-to-end).
- All regression fixtures rolled back; Egypt stock restored. VPS production deployment and backup verification remain pending.
- **Open safety issue:** hosted initiation currently makes a network request while holding an order row lock. A timeout after provider acceptance may leave no local payment record, so a later retry with a new idempotency key could initiate a second charge. Do not enable hosted checkout in production until durable initiation reservation, same-key retry semantics and recovery have been implemented and tested.


### Durable reservation local verification — 2026-11-09
- Additive migration `2026_11_09_130000_create_myfatoorah_checkout_reservations_table.php` applied successfully on **local XAMPP only**.
- Hosted checkout controller now commits an order/idempotency-key reservation **before** calling the provider, without holding the order transaction lock across HTTP. A completed same-key request returns the saved invoice/URL; a different key is rejected; ambiguous provider failures leave an `uncertain` reservation and prevent automatic new initiation.
- Developer's latest local run: **hosted 14/14 PASS**, **HTTP webhook 20/20 PASS**, **end-to-end 22/22 PASS** = **56/56 checks across three suites**. Database fixtures and Egypt stock restored.
- **Remaining safety work:** test timeout and same-key replay of an *uncertain* reservation explicitly; test process crash between reservation and provider request, concurrent requests, and failure between provider success and database commit. Add controlled reconciliation/manual resolution and payment failed/cancelled lifecycle; no automatic second charge.
- **Still pending:** guest hosted checkout integration, frontend API contract, live sandbox API schema verification/real sandbox transaction, and production hardening. No live VPS deployment or migration.

### Additional retry/crash regression verification — 2026-11-09
- Developer pulled commit `4d19ab7`, PHP lint passed and ran hosted checkout regression: **16/16 PASS** plus rollback/stock restoration.
- Added scenarios confirmed: uncertain same-key retry returns 202 without a second provider POST; different-key retry rejected; interrupted `requesting` reservation replay fails closed without another provider call.
- Latest verified suites: hosted **16/16**, HTTP webhook **20/20**, end-to-end **22/22** = **58/58** (the latter two from prior runs).
- **Next:** implement authenticated, controlled reconciliation of uncertain reservations and explicit failed/cancelled handling, without trusting browser callbacks or allowing unsafe automatic re-initiation. Real sandbox and production verification remain pending.
