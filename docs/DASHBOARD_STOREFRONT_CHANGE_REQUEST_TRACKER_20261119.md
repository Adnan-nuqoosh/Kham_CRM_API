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

- **Storefront coupons:** No coupon acceptance in live public quote/production checkout. Enable only after coupon eligibility, redemption limits, idempotency, taxes, usage rollback, and price parity tests. Do not merely remove `prohibited` validation or flip `coupons_enabled`.
- **Public guest order lookup:** Not applicable to current authenticated-only production checkout; no unauthenticated order lookup is added, to avoid disclosing customer orders. A future guest-checkout product decision must precede designing a rate-limited proof-of-ownership lookup.
- **MyFatoorah/live checkout:** Keep `COMMERCE_STOREFRONT_PRODUCTION_CHECKOUT_ENABLED=false`, `MYFATOORAH_LIVE_ENABLED=false`, and `COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED=false` until market readiness, verified merchant/webhooks and tax/shipping approval. Keep test checkout local-only and isolated from production data.
- Legacy `docs/KHAM_Commerce_API_OpenAPI.yaml` now carries an explicit deprecation header. `docs/openapi.json` is authoritative.
- Frontend dashboard refunds UI, storefront customer account pages, market selection consuming `/storefront/markets`, and secure isolated staging checkout connection require **frontend repo changes**, not this backend branch.
- Product catalog filters, Arabic field schemas, finance pagination, tax/shipping descriptions and storefront account contracts are updated in `docs/openapi.json`; see `docs/CHANGE_REQUEST_OPERATOR_GUIDE_20261119.md` for RBAC, refunds, sandbox requirements and error contract. Ingredient/quiz archive semantics documentation is **explicitly excluded at the requester's direction**.

## Release / verification checklist

1. Review the PR and run `php -l` on changed PHP files, `composer test` / `php artisan test` with an **isolated test DB**. Do not run against any live DB.
2. Update staging PHP code via normal release tooling, back up staging DB, then execute `php artisan migrate --force` **in staging only**, after verifying .env and DB_HOST/DB_DATABASE. The added storefront reset-token table is additive and does not alter existing products.
3. Configure SMTP and a real HTTPS customer reset form, then set `STOREFRONT_PASSWORD_RESET_FRONTEND_URL` server-side (never commit it with secrets).
4. Test wrong-current-password, valid update, invalid/expired reset tokens, token revocation, anonymous access, role-based denial, existing CRM password reset behavior, and API response consistency.
5. Update both frontend apps to consume the new account endpoints and test dashboard permission gating, refund rejection/cancellation, and market selection.
6. Only after separate approved deployment and production backup should an additive migration be reviewed for production. **Do not run `db:seed`, `migrate:fresh`, or enable live payments.**

## Status

This branch implements customer profile/password changes, isolated reset infrastructure, seeder parity, OpenAPI catalog/finance/bilingual corrections and detailed operator docs. Archive semantics are skipped as requested; legacy frontend market IDs are outside backend scope. **Still pending**: frontend UI changes, live environment/SMTP setup, staging end-to-end verification, safe coupon redemption support, and payment/shipping/tax production launch gates. Guest order lookup is not applicable to the current authenticated-only production checkout.
