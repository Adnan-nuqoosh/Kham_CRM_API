# Community Page — Storefront API

Public endpoint: `GET /api/v1/storefront/community?locale=en` (also supports `ar`).

Returns the standard API success envelope with `code=STOREFRONT_COMMUNITY`. The `data` object contains `locale`, `title`, `intro`, `sections`, and `links`. No authentication is required; storefront throttling applies. Invalid locales return HTTP 422.

Content lives in `config/community.php`. Only sections with `is_published=true` are exposed, ordered by `sort_order`. The initial sections and links are empty on purpose: approved community events, member profiles, and testimonials have not been provided. English/Arabic fields fall back to English. The API does not collect personal data or accept submissions.

Frontend: render the title/intro and only show optional sections/links when nonempty. Avoid displaying invented testimonials, event dates, or signup functionality. Changes to the config require normal application deployment/config cache refresh.

This is a **static/config-backed first phase**, not a CRM-editable CMS. A future CRM editor would require a separate scoped design and potentially a database migration, which is not authorized here.

Local verification (after pull): `php artisan route:list --path=api/v1/storefront/community`, `php scripts/local_openapi_full_coverage.php`, and `npx --yes @redocly/cli lint docs/openapi.yaml`. Functional endpoint testing requires a running local Laravel application.
