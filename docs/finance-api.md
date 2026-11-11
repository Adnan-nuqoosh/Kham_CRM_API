# KHAM CRM Finance API (WIP)

All endpoints require Bearer authentication and Spatie permission `finance.view` or `finance.manage`. Base: `/api/v1`.

| Method | URL | Permission |
|---|---|---|
| GET | /finance/report?period=daily&market_id=1 | finance.view |
| GET | /finance/report?period=weekly&market_id=1 | finance.view |
| GET | /finance/report?period=monthly&market_id=1 | finance.view |
| GET | /finance/report?period=custom&from=2026-10-01&to=2026-10-16&market_id=1 | finance.view |
| GET | /finance/report/export.csv?period=monthly&market_id=1 | finance.view |
| GET | /finance/expenses?market_id=1&from=2026-10-01&to=2026-10-31 | finance.view |
| POST | /finance/expenses | finance.manage |
| PATCH | /finance/expenses/{expense} | finance.manage |
| DELETE | /finance/expenses/{expense} | finance.manage |
| PATCH | /finance/order-items/{item}/cost | finance.manage |

Create expense JSON: `{"market_id":1,"category":"marketing","description":"Campaign","amount":150.00,"incurred_on":"2026-10-16","reference":"INV-101"}`. Expense currency derives from market, not user input. Cost patch JSON: `{"unit_cost_snapshot":20.50}`.

Report: `data` contains `currency_code`, `delivered_sales`, `paid_delivered_sales`, `operating_expenses`, `known_cost_of_goods`, `missing_cost_items`, `gross_profit`, `net_profit`, `profit_status`, `expense_categories`. Never combine numbers across markets with different currency codes. `net_profit=null` means unit costs missing. Non-null profit remains **provisional**; do not represent as accounting final profit without checking refunds, returns, taxes, shipping, payment reconciliation and recognizing sales on delivered date rather than order placement date.

**Deployment hold:** This is a draft implementation. Do not merge/deploy until schema verification, testing, backup, financial policies and existing frontend coordination. Never run `php artisan db:seed --force` in production to add finance permissions: current DatabaseSeeder updates the admin password.
