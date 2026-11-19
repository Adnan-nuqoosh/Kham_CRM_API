# KHAM frontend handoff — exact source repository required

The currently connected GitHub repository `CRM_front_end-` is **Nuqoosh CRM Professional V3**, not KHAM. Do not copy KHAM code there.

This PR contains **portable source templates** only. They are not compiled in Laravel CI and are not attached to the actual website/dashboard until their correct source repos are accessible.

## KHAM dashboard

Copy `integrations/kham-dashboard/refunds/RefundActions.tsx` into the actual KHAM CRM dashboard and mount it in the order details panel, supplying the real authenticated CRM bearer token, CRM API base URL, currentUserId, orderId and permission array from `GET /api/v1/auth/me`. The UI:

- Lists refund requests from `GET /orders/{order}/refund-requests` (orders.view).
- Shows Approve/Reject only for `pending_approval` when `refunds.approve` is granted and `requested_by !== currentUserId`.
- Shows Cancel only to original requester when `orders.manage` is granted.
- Reject requires a 5+ character reason; approve requires `confirm: true`. Disabled actions stay unavailable while requests are running.
- Renders API field errors and refreshes the refund list after a successful action.
- Does not initiate MyFatoorah refunds; approval is `approved_awaiting_provider`, not provider-paid.

**Verification remaining:** UI click-through with two accounts (requester vs approver), failure/error states, audit logs, no accidental cross-order access.

## KHAM website storefront

Copy `integrations/kham-storefront/account/*` into the actual Next.js storefront and bind the forms to existing account/login routes and authenticated token retrieval. The portable components offer profile edit, change password, forgot password and token-based password reset.

- `GET/PATCH /api/v1/storefront/auth/me`
- `POST /api/v1/storefront/auth/change-password`
- `POST /api/v1/storefront/auth/forgot-password`
- `POST /api/v1/storefront/auth/reset-password`

Ensure `STOREFRONT_PASSWORD_RESET_FRONTEND_URL` is an **existing HTTPS page** and email delivery is tested before enabling the recovery UI. Do not log token/email query parameters or include them in analytics.

For staging checkout, follow `integrations/nextjs-staging-checkout/README.md`. It requires wiring an existing checkout page, a server-only key, building Next.js, and verifying staging CRM orders. Backend GitHub pull alone does not install these files in Next.js.

## Acceptance criteria before claiming complete

1. KHAM dashboard frontend code is in its correct repo, compiled, and refund actions visibly tested.
2. KHAM website frontend code is in its correct repo, account and checkout screens work and errors are displayed.
3. Isolated staging API and website are deployed and one mock order is placed and found using **staging** CRM orders API.
4. No production data/payment modification; live MyFatoorah and public coupons remain OFF.


## Payment recovery contract (backend PR #8)

Customer-facing checkout `POST /api/v1/storefront/checkout` is protected with a **storefront customer Bearer token**; MyFatoorah merchant tokens remain private to the Laravel server. A returned hosted payment URL is not proof of paid status. On provider uncertainty or network timeout, **do not generate a fresh idempotency key or create a second order**. Fetch `GET /api/v1/storefront/orders/{orderNumber}` using the same customer's token, and inspect `data.payment_recovery`:

- `payment_status`: stored order payment status, not browser return status.
- `initiation_status`: hosted reservation status or `null`.
- `can_resume_payment`: true only if the order is pending, is not cancelled/returned, and its existing reservation is `initiated` with an HTTPS payment URL.
- `payment_url`: resumable existing hosted payment URL or `null`. Redirect only after user chooses to continue; never embed merchant credentials.
- `requires_reconciliation`: true for pending reserved/requesting/uncertain sessions. Show a support/review state, not a new Pay Now operation.

Only the order's storefront owner can read this payload (otherwise 404). The frontend should preserve the same idempotency key across retries of a single checkout and use server responses `CHECKOUT_REUSED`, `CHECKOUT_RECONCILIATION_REQUIRED`, `PAYMENT_INITIATION_UNCERTAIN` and `STOREFRONT_CHECKOUT_CREATED` explicitly. Final paid state is confirmed only by a verified MyFatoorah webhook plus provider enquiry.

Pricing: the public quote already returns `tax_calculation_method`, `estimated_total`, `final_payable_total`, `checkout_ready`, and `checkout_blockers`. Use `final_payable_total` only if ready; do not add inclusive tax to displayed price a second time. Market IDs must come from `GET /api/v1/storefront/markets`, not hardcoded numeric mappings. Use readiness from the **selected checkout market**, not a different header market.
