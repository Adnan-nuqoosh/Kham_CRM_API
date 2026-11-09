# KHAM CRM API — MyFatoorah Integration Progress

Updated: 2026-11-09
Branch: `feature/myfatoorah-v3-webhook-verification-20261108`
Environment: Windows XAMPP, Laravel 12, local MySQL `kham_crm_api`

## Verified by developer (local runs)

- Signed webhook HTTP regression: **20/20 checks passed**, database fixtures and Egypt stock restored.
- Hosted checkout mocked regression: **8/8 checks passed**, database fixtures and Egypt stock restored.
- Routes registered: `POST /api/v1/storefront/payments/myfatoorah/local-webhook` and authenticated `POST /api/v1/orders/{order}/payments/myfatoorah/sandbox-hosted`.
- PHP lint for hosted controller and hosted regression passed.
- All provider calls in these tests are mocked. **No live sandbox payment was confirmed.**

## Current implementation

- Local/testing-only MyFatoorah V3 client, hosted payment creation and read-only provider enquiry.
- CRM-authenticated, `orders.manage` guarded hosted checkout; server-controlled amount and redirect, invoice ID stored on a pending payment attempt.
- Signed webhook verifies event, reconciles provider invoice/payment/order/amount/currency, and atomically stores receipt plus paid transition.
- Idempotent callback, cancelled-order guard, stock unaffected.
- Hosted checkout is disabled by default: `MYFATOORAH_SANDBOX_HOSTED_CHECKOUT_ENABLED=false`. Sandbox gateway disabled by default.
- Local guest checkout remains a separate **mock_fatoora** flow; it is not connected to the hosted gateway.

## Next milestones (not yet verified)

1. End-to-end mocked **hosted initiation → signed webhook → paid order/payment/receipt** regression script added at `scripts/local_myfatoorah_end_to_end_regression.php` (commit `8669508`). **Local execution and PASS confirmation still pending.**
2. Review payment creation idempotency and concurrency, crash recovery if provider accepts payment but local DB write fails; avoid holding DB row locks across network calls.
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
