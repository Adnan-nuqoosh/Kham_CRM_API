# Refund provider reconciliation gate

This is a **fail-closed schema foundation**, not a refund settlement integration.

- `refund_provider_evidence` stores a provider and refund reference with a database uniqueness constraint. Each approved refund request can have at most one evidence row.
- The record is always **unverified**. No provider refund API, refund callback, or payment/order status transition is implemented.
- Amount and currency must match the approved refund request; references must be nonempty.
- `RefundProviderEvidence::recordUnverified()` is an internal-only helper, not an HTTP route. Do not call it with untrusted provider notifications.
- A reference is not proof of refund completion. Future provider integration must verify authenticity, status, amount, currency, original payment association and idempotency before allowing settlement.
- Do not change payment/order statuses or release inventory based on this evidence.
- Do not use this schema as a substitute for a verified real MyFatoorah sandbox contract.
