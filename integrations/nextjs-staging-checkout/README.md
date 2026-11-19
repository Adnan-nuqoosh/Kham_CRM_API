# KHAM staging Next.js checkout adapter (copy-only)

The real KHAM Next.js storefront is deployed at `/home/khambeauty/releases/kham_20261117/website` on the WHM server, but its **frontend GitHub repository is not linked/identified** in the current GitHub installation. These templates live in the backend repo solely as a reviewable, copyable integration package; `git pull` on the Laravel backend **does not** add these files to the separate Next.js website automatically.

## Goal and security boundary

- Production storefront API remains `https://crm.khambeauty.com/api/v1`. Never globally point the browser to local port 8101.
- Next.js server route `src/app/api/test-checkout/route.ts` forwards `POST /api/test-checkout` to loopback-only staging API `127.0.0.1:8101/api/v1/storefront/checkout/test-order`.
- Requires a strong `KHAM_STAGING_CHECKOUT_KEY` available only as a **server-only** environment variable. The browser's authorized QA operator supplies the secret to the checkout helper at submit-time. Do not publish this key through `NEXT_PUBLIC_` variables, logs, examples or public pages.
- The backend accepts local mock payments only with `APP_ENV=local` + `COMMERCE_STOREFRONT_TEST_CHECKOUT_ENABLED=true`. Real MyFatoorah payments are never initiated by this route.
- Requests fail closed for invalid origin/host/secret and never accept client-supplied financial totals. Backend revalidates prices, quantities, market shipping and stock; middleware rate limits apply.
- Protect the entire staging site using a dedicated QA access control if it must be internet-facing. This secret header alone is not a substitute for full authentication, rate limiting and a staging network policy.

## Integration steps once the actual frontend repo becomes available

1. Add `route.ts` as `src/app/api/test-checkout/route.ts`, and `storefront-test-checkout.ts` to the frontend's API utilities. Do not add these files to the production storefront deployment.
2. Generate a 32-byte random hexadecimal QA key at deployment time. Put it in **Next.js server** `.env.local` as `KHAM_STAGING_CHECKOUT_KEY`; do not commit or print it.
3. Checkout UI should call `submitStagingTestCheckout(payload, accessKey)` **only after valid quote**. Display `data.order_number` on success; clear cart only after the 201/200 response with `data.is_test_order=true`.
4. `idempotency_key` must remain stable when retrying **the same order** after a network timeout; create a new key when the cart/payload changes.
5. Ensure the server service is bound only to `127.0.0.1:8101`; disallow direct public access to that port. The staging route must not be carried into the production build.
6. After updating actual frontend source, run `npm ci && npm run build`, verify `ƒ /api/test-checkout` is in route list, restart staging service using its actual service manager.
7. Submit one sample test order and confirm CRM admin's **staging-authenticated** `GET /api/v1/orders?search=<order_number>` returns it, and stock reserved exactly once. Production CRM dashboard remains on a separate production database.

## Do not do

- Do not touch/seed production inventory, enable payment flags or run `migrate:fresh` / `db:seed` against live databases.
- Do not expose real payment credentials, private test key, production token, or raw API errors to the browser.
- Do not claim the frontend is integrated until these templates are copied into the **correct** website repo, built, and an order is observed on staging.
