# KHAM Commerce OS — API documentation (Phase 1)

The curated OpenAPI 3.0.3 contract is at `docs/openapi.yaml`. Import it into Apidog, Swagger Editor, or another OpenAPI 3 reader. It is **not yet the complete route catalog**; it covers homepage CMS, partner applications, and newsletter subscribe. Existing `docs/APIDOG_SETUP.md`, `docs/APIDOG_TESTING_GUIDE.md`, and `docs/API_ENDPOINTS.md` remain available.

## Local setup
- Base URL: `http://localhost` (adjust if your XAMPP virtual host uses another URL).
- All documented routes start with `/api/v1`.
- CRM routes use `Authorization: Bearer <SANCTUM_TOKEN>` and require the permissions noted in each operation. Never commit real tokens.
- Public routes do not require a token; write endpoints have throttling.
- Response envelope typically includes `success`, `code`, `message`, `data`, `meta`, `errors`.
- HTTP 401: missing authentication; 403: insufficient permissions; 422: invalid input; 429: throttled.

## Sample safe requests
```bash
curl -H "Accept: application/json" "http://localhost/api/v1/storefront/homepage?locale=ar"
```

```json
{
  "contact_name": "Example Person",
  "email": "example@example.invalid",
  "company_name": "Example Company",
  "partner_type": "retailer",
  "message": "Interested in partnership"
}
```
POST this JSON to `/api/v1/storefront/partners/apply`. The public response deliberately omits applicant PII.

## Contract checks
Run `php scripts/local_openapi_contract_check.php` after `git pull`. This is a **static route/method coverage smoke check**, not a full YAML/OpenAPI semantic validator. The script does not connect to or modify the database. Use an OpenAPI 3 validator in your API documentation tool for schema validation.

## Next documentation phases
Document remaining CRM, catalog, inventory, checkout, shipping, finance, refunds, quiz, reviews, ingredients, rituals, brands, and authentication endpoints; add complete response examples and error schemas; validate all paths against the live route list. Do not publish internal-only payment test routes as production-ready.

## Full route inventory (Phase 2)
The OpenAPI file now inventories all current Laravel API route/method operations. Entries with `x-documentation-status: route-inventory-only` are **not fully specified contracts**: request bodies, exact response status codes, and endpoint-specific response schemas still need review. Never infer production readiness from inclusion, particularly local/sandbox payment routes.

Run:
```bat
php -l scripts/local_openapi_full_coverage.php
php scripts/local_openapi_full_coverage.php
```
The checker compares Laravel's registered `api/v1` methods (excluding automatic HEAD) against the OpenAPI paths. A single Laravel route may register multiple methods (e.g. PUT|PATCH), so the operation count can exceed the route count. This is a route inventory check, not a full OpenAPI schema validator. No migrations or database writes are performed.
