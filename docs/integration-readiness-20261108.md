# KHAM Backend Integration — Local Validation Gate

Base: feature/egypt-central-fulfillment-20261108
Working branch: feature/integrated-backend-20261108

## Included in this checkpoint

- Existing tested Egypt central fulfillment, shipping rules, and internal CRM configured shipping.
- Finance expenses, reporting, permissions, migrations and API routes from feature/finance-sales-reporting-20261016.
- OrderItem allows explicit unit_cost_snapshot updates.

## Not yet included

- Storefront branch. It overlaps OrderController, OrderService, Order model and has a distinct warehouse resolver; it must be adapted rather than merged blindly.
- Full Odoo-style chart of accounts and accounting journal.
- Approved tax, FX and COGS currency conversion policies.
- Production deployment.

## Required local checks

1. Back up the local database before migrating.
2. Switch to this branch and pull.
3. Run php artisan migrate:status, then php artisan migrate.
4. Run php artisan route:list --path=api/v1/finance -v.
5. Run php artisan route:list --path=api/v1/orders -v.
6. Run php scripts/local_egypt_fulfillment_smoke.php.
7. Run php scripts/local_orders_api_regression.php.
8. Run php scripts/local_configured_order_shipping_smoke.php.
9. Run php scripts/local_shipping_quote_smoke.php.
10. Run php scripts/fulfillment_readiness_preflight.php.

## Financial correctness gate

Profit is provisional until missing order item costs, currency conversion,
shipping expense, refunds, tax accounting, and recognition timing are resolved.
Never treat shipping_total (customer charge) as shipping carrier expense.
Do not mix EGP, AED, and SAR in one currency-agnostic profit number.
