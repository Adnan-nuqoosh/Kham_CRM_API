# MyFatoorah V3 sandbox compatibility gate (2026-11-09)

**Status: BLOCKED for real-payment rollout.** This is an engineering checklist, not a record of a successful live sandbox payment.

## Verified from official MyFatoorah documentation

- Hosted payment creation: POST `https://apitest.myfatoorah.com/v3/payments` with `Order.Amount`, `IntegrationUrls.Redirection`, and optionally `PaymentMethod`.
- The hosted create response has `Data.InvoiceId`, `Data.PaymentURL`, and often `Data.PaymentId: null`. A redirect URL is not payment proof.
- The return URL may receive `paymentId`; read-only enquiry is `GET /v3/payments/{paymentId}`.
- Enquiry has `Invoice.Id`, `Invoice.Status`, `Invoice.ExternalIdentifier`, `Transaction.PaymentId`, `Transaction.Status`, `Amount.DisplayCurrency`, and `Amount.ValueInDisplayCurrency`.
- The official hosted example returns `Invoice.ExternalIdentifier: null`. Therefore **do not assume the provider populates it automatically**.

Sources:
- https://docs.myfatoorah.com/docs/v3-hosted-payment-page
- https://docs.myfatoorah.com/reference/create-payment
- https://docs.myfatoorah.com/docs/get-payment-details

## Current KHAM integration status — account-independent development

`MyFatoorahV3Client::createHostedPayment()` now sends the server-generated KHAM order number as `Order.ExternalIdentifier` alongside the reserved amount. The outgoing JSON is asserted in the local mocked hosted regression. **This request field has NOT been verified against a real MyFatoorah V3 sandbox account.** The official hosted example returns `Invoice.ExternalIdentifier: null`; an actual provider response may reject the field or not echo it. Both signed-webhook reconciliation and operator enquiry deliberately fail closed if `Invoice.ExternalIdentifier` does not equal the KHAM order number. Do not relax that check or fabricate a signed event.

**Manager-approved deferred work:** No merchant account or test API token is available. Account-independent mocked tests can be completed, but real gateway compatibility, genuine signed webhook delivery, and production readiness remain blocked until an authorized account is supplied. Never claim a mocked gateway response proves provider compatibility.

## Real sandbox acceptance sequence (manual, with approved sandbox token)

1. Confirm the correct country/account, display currency, enabled methods, redirect domain, webhook signing scheme and V3 Order external-reference field.
2. Use a test order and a sandbox-only token. Never log or paste the token.
3. Initiate exactly one hosted payment and record the KHAM order number, provider invoice ID and payment ID (if supplied).
4. Complete the sandbox payment. Enquire by payment ID and compare invoice ID, external identifier, amount, display currency and both statuses.
5. Deliver the genuine signed webhook; verify one atomic settlement, replay idempotency and rejection of tampered/mismatched events.
6. Exercise pending, failed, timeout and uncertain initiation cases without creating a second provider payment.
7. Keep production flags disabled until sandbox evidence and deployment/rollback review are complete.

## Local regression baseline

Latest user-confirmed local mocked checks: hosted **31/31** (commit `c3a2d57`), webhook HTTP **20/20**, end-to-end **22/22**, HTTP security **6/6** (**79/79** combined across separate runs). They do **not** establish provider compatibility.

## Handover boundary

- **Completed locally:** server-side amount and order-reference generation, disabled-by-default sandbox initiation, durable idempotency reservations, read-only provider enquiry, signed-event validation, atomic settlement, webhook replay protection, mocked failure cases, rollback-only database fixtures.
- **Deferred, not complete:** provider acceptance of `Order.ExternalIdentifier`, actual sandbox payment, authentic provider webhook, real redirect callback, production token/domain configuration, live deployment and production reconciliation monitoring.
- **Activation gate:** obtain an authorized MyFatoorah sandbox account/token privately; execute the real acceptance sequence above and resolve any schema differences before enabling real transactions. No live VPS or production configuration changes are authorized by this document.
