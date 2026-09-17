# Changelog

## 0.1.0 - Build 1
- API-first Laravel 12 foundation
- Authentication and role/permission base
- Multi-market and multi-currency data model
- Product/variant/catalog domain
- Market-specific prices
- Warehouse stock and immutable movement ledger
- Customer CRM foundation
- Order creation with transactional stock reservation
- Shipment and payment entities
- Coupons foundation
- Dashboard KPIs

## Build 1.4
- Fixed API-only unauthenticated requests returning `Route [login] not defined`.
- Added standardized JSON `401 AUTH_UNAUTHENTICATED` response for protected API routes.
- Added standardized JSON `403 AUTH_FORBIDDEN` response for Spatie permission failures.
