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

## Current KHAM gap — must fix before real sandbox checkout

`MyFatoorahV3Client::createHostedPayment()` currently sends only `Order.Amount`; it does not send the KHAM order number. Both the signed-webhook reconciler and the operator enquiry require `Invoice.ExternalIdentifier === order_number`. Official hosted examples show null, so a real payment may remain permanently unreconcilable even when provider reports PAID.

**Do not relax the external identifier check or fabricate a signed event.** First establish the exact supported V3 request field through the provider's complete Order object schema or provider support. If confirmed, pass a server-generated order reference from the locked order, not from the browser. Assert the outgoing JSON body in a mocked regression and verify that GET payment details and signed webhook echo the exact reference in the real sandbox.

## Real sandbox acceptance sequence (manual, with approved sandbox token)

1. Confirm the correct country/account, display currency, enabled methods, redirect domain, webhook signing scheme and V3 Order external-reference field.
2. Use a test order and a sandbox-only token. Never log or paste the token.
3. Initiate exactly one hosted payment and record the KHAM order number, provider invoice ID and payment ID (if supplied).
4. Complete the sandbox payment. Enquire by payment ID and compare invoice ID, external identifier, amount, display currency and both statuses.
5. Deliver the genuine signed webhook; verify one atomic settlement, replay idempotency and rejection of tampered/mismatched events.
6. Exercise pending, failed, timeout and uncertain initiation cases without creating a second provider payment.
7. Keep production flags disabled until sandbox evidence and deployment/rollback review are complete.

## Local regression baseline

Latest user-confirmed local mocked checks: hosted 30/30, webhook HTTP 20/20, end-to-end 22/22, HTTP security 6/6 (78/78 combined across runs). They do **not** establish provider compatibility.
