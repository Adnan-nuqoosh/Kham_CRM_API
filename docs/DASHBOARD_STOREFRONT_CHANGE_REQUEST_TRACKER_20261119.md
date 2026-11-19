# KHAM Dashboard + Storefront Backend Change Requests — 2026-11-19

This checklist tracks the two frontend-team change request documents against the source code. **A route existing is not the same as a tested UI, active deployment, approved policy, or payment-ready release.** No staging/production migrations, seeders, payment enablement, or deployments are part of this change branch.

## Implemented on this branch

- Seed reconciliation: `DatabaseSeeder` includes `finance.view`, `finance.manage`, `refunds.approve` and `tax.approve` for future clean installations. Existing `2026_11_18_030000_assign_finance_approval_permissions.php` already provisions the **Finance Approver** role and relevant grants without assigning users. **Never re-run the full seeder on production** to apply just these permissions.
- Existing `/auth/me` already returns the user plus `roles.permissions`. UI should hide controls according to the permissions actually returned.
- New authenticated customer profile endpoint: `PATCH /api/v1/storefront/auth/me`; accepts only `first_name`, `last_name`, `phone`. Email/identity and password cannot be changed in a profile request.
- New password change endpoint: `POST /api/v1/storefront/auth/change-password`; `current_password`, `password`, `password_confirmation`, minimum 12 characters. Other access tokens are revoked; current token is left active.
- New storefront-only password broker/table and `POST /storefront/auth/forgot-password`, `POST /storefront/auth/reset-password`. This uses a separate password-reset token table and **does not reset CRM admin passwords**. Forgot-password is disabled (503) until `STOREFRONT_PASSWORD_RESET_FRONTEND_URL` points to a genuine HTTPS password-reset page with an operational mail transport. Links supply URL query `token` and `email`. The API does not disclose whether an email exists.
- OpenAPI `docs/openapi.json` includes these four new customer account operations. Feature tests were added.

## Already supported in baseline (not a new branch implementation)

- `GET /auth/me` loads role permissions.
- `GET /finance/report/export.csv` responds with `text/csv` and filename instead of JSON; OpenAPI already describes it.
- Admin `PATCH /partner-applications/{application}` accepts `status` and `internal_notes`, and `PartnerApplicationAdminUpdate` schema was already present.
- Public quote prohibits `coupon_code`; CRM quote permits it. OpenAPI already describes the different contracts.
- Shipping rule update protects immutable `market_id` and `city`. `*` is the optional wildcard city.
- Market tax changes reset `is_approved`, `approved_at`, `approved_by`; these fields are returned by its model.
- Finance expenses support `per_page=1..100` and pagination metadata.
- Refund request `reject` and `cancel` backend routes exist, with requester/approver separation. The actual dashboard UI must call them.
- `ApiResponse` standardizes `success`, `code`, `message`, `data`, `meta`, `errors`.

## Intentionally not enabled automatically

- **Storefront coupons:** Gated backend implementation now exists: coupon usage limits, single-market restriction for fixed discounts, atomic redemption on order creation, checkout/quote net-subtotal parity, and dedicated automated tests, including quote-to-idempotent-checkout integration coverage (no duplicate redemption on retry). `COMMERCE_STOREFRONT_COUPONS_ENABLED` defaults false. Business approval, frontend promo field and complete refund/payment lifecycle verification remain mandatory before enabling this switch in production.
- **Public guest order lookup:** Not applicable to current authenticated-only production checkout; no unauthenticated order lookup is added, to avoid disclosing customer orders. A future guest-checkout product decision must precede designing a rate-limited proof-of-ownership lookup.
- **MyFatoorah/live checkout:** Keep `COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED=false`, `MYFATOORAH_LIVE_ENABLED=false`, and `COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED=false` until market readiness, verified merchant/webhooks and tax/shipping approval. Keep test checkout local-only and isolated from production data.
- Legacy `docs/KHAM_Commerce_API_OpenAPI.yaml` now carries an explicit deprecation header. `docs/openapi.json` is authoritative.
- Frontend dashboard refunds UI, storefront customer account pages, market selection consuming `/storefront/markets`, and secure isolated staging checkout connection require **frontend repo changes**, not this backend branch.
- Product catalog filters, Arabic field schemas, finance pagination, tax/shipping descriptions and storefront account contracts are updated in `docs/openapi.json`; see `docs/CHANGE_REQUEST_OPERATOR_GUIDE_20261119.md` for RBAC, refunds, sandbox requirements and error contract. Ingredient/quiz archive semantics documentation is **explicitly excluded at the requester's direction**.

## Latest QA milestone — isolated mock order → CRM Orders API

- Fixed a mismatch in local-only mock checkout: non-payable `mock_fatoora` QA orders with `tax_total=0` may be created without an approved production tax policy **only** when `APP_ENV=local`, `COMMERCE_STOREFRONT_TEST_CHECKOUT_ENABLED=true` and the explicitly trusted local controller passes its test-order override. Production `OrderService::create` still requires an approved market tax policy in configured mode.
- Added `tests/Feature/LocalMockOrderCrmFlowTest.php` to verify creation of the mock order, a pending mock payment, one and only one order after the same idempotency key is retried, correct stock reservation, and visibility through the authenticated `GET /api/v1/orders` CRM endpoint. The disabled-route behavior is also covered.
- This is automated **isolated test DB** coverage, not evidence that `staging.khambeauty.com` currently reaches `127.0.0.1:8101` or displays the order. A secure Next.js server-side proxy and frontend/order dashboard integration must still be deployed and verified separately.

## GitHub-only staging frontend handoff

The KHAM Next.js storefront's actual GitHub repository is **not among the accessible repositories** of the linked GitHub account. The available `CRM_front_end-` repository is for Nuqoosh CRM, **not KHAM**, so it must not be modified.

This branch now includes a copy-only integration handoff under `integrations/nextjs-staging-checkout/`:

- `route.ts`: a server-side, mock-only Next.js `/api/test-checkout` route that forwards to the isolated loopback staging API, rejects invalid origin/host/secret, and fails closed when no secret is set.
- `storefront-test-checkout.ts`: a typed frontend helper for submitting the test order with a server-only access key supplied by an authorized QA operator.
- `README.md`: exact copy targets, separate production/staging boundaries, build prerequisites, checkout response handling, idempotent retry and CRM verification.

**This does not wire the existing website checkout button.** The templates must be moved to the real KHAM Next.js repository and integrated with the actual checkout UI before `git pull` on that frontend repo can deploy them.

## Release / verification checklist

1. Review the PR and run `php -l` on changed PHP files, `composer test` / `php artisan test` with an **isolated test DB**. Do not run against any live DB.
2. Update staging PHP code via normal release tooling, back up staging DB, then execute `php artisan migrate --force` **in staging only**, after verifying .env and DB_HOST/DB_DATABASE. The added storefront reset-token table is additive and does not alter existing products.
3. Configure SMTP and a real HTTPS customer reset form, then set `STOREFRONT_PASSWORD_RESET_FRONTEND_URL` server-side (never commit it with secrets).
4. Test wrong-current-password, valid update, invalid/expired reset tokens, token revocation, anonymous access, role-based denial, existing CRM password reset behavior, and API response consistency.
5. Update both frontend apps to consume the new account endpoints and test dashboard permission gating, refund rejection/cancellation, and market selection.
6. Only after separate approved deployment and production backup should an additive migration be reviewed for production. **Do not run `db:seed`, `migrate:fresh`, or enable live payments.**

## Status

This branch implements customer profile/password changes, isolated reset infrastructure, seeder parity, OpenAPI catalog/finance/bilingual corrections and detailed operator docs. Archive semantics are skipped as requested; legacy frontend market IDs are outside backend scope. **Still pending**: frontend UI changes, live environment/SMTP setup, staging end-to-end verification, coupon release approval and frontend UI, and payment/shipping/tax production launch gates. Guest order lookup is not applicable to the current authenticated-only production checkout.
