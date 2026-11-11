# Refund approval and provider reconciliation gate

## Implemented (approval-only)

- Authenticated CRM users with `orders.manage` can request a **full** refund for a paid order with exactly one matching paid payment.
- Requests start as `pending_approval`; duplicate open requests are rejected under the order row lock.
- `refunds.approve` permission is required for approval. The requester cannot approve their own request.
- Finance approval changes **only** the request to `approved_awaiting_provider`; neither `payments.status` nor `orders.payment_status` changes.
- Existing order cancellation gate blocks direct cancellation for paid, authorized and refunded payment states. Inventory is not released by refund approval.
- No provider refund request is sent. No callback or manual CRM action is authorized to mark a payment refunded.
- New permission is registered but deliberately **not assigned to any role**. Assign it only to a vetted finance approver using the normal role administration process.
- Migration is additive; do not run `db:seed` to register the permission.

## Blocked pending real MyFatoorah sandbox validation

Do not implement a payment-status transition based on approval, a browser redirect, a signed event alone, or a finance operator's assertion. A future provider-confirmation handler must verify refund ID, payment/invoice identity, exact amount/currency, original order, approval, and uniqueness, using authoritative provider enquiry, atomic DB locks, and replay-safe receipt storage. Test partial refunds separately before enabling them.

**Production deployment, refund execution, final payment-state transition, and live checkout remain disabled.**
