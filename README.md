## BizFlow — Phase 1 Foundation

A Vite + React application backed by a PHP 8.2 JSON API, PDO SQLite, and secure PHP sessions. The implementation intentionally retains the repository's PHP/SQLite stack rather than migrating to Supabase.

### Phase 1 delivered

- New registrations create a business workspace and owner membership in the same database transaction.
- The business type is selected during setup and stored with configurable currency and timezone (initial defaults: `PHP` and `Asia/Manila`).
- Supported business types: Retail, Grocery, Pharmacy, Clinic, Bakery, Hotel, Restaurant, Rent-a-car, Computer store, and Coffee shop.
- Existing single-workspace data is preserved and assigned to a legacy Retail workspace during the one-time additive schema migration.
- Active business membership, role, and business selection are validated by the PHP API. The browser may send `X-Business-Id` to select among memberships, but the server rejects any business the signed-in user does not belong to.
- Current catalog, category, supplier, inventory, dashboard, and POS operations are scoped to the active business. Team membership and roles are managed per business; only existing accounts can be added.
- The current POS is exposed only for supported POS industries and the API enforces that restriction independently of navigation.
- Retail businesses have a dedicated `/retail/dashboard` with business-timezone-aware date filters, paid-sales KPIs, trend and payment charts, product/category performance, inventory status, and recent transactions. Dashboard values are queried from the tenant-scoped SQLite records; voided and pending sales are excluded from sales totals.

### Retail module status

The dashboard, existing shared POS, catalog, inventory, supplier, and transaction-history surfaces form the current retail foundation. Retail dashboard aggregation is tenant-checked in the PHP API, validates date ranges, and calculates date boundaries in the configured business timezone. The current SQLite schema does not yet provide business locations or per-location stock, so the dashboard cannot filter by location.

The requested retail extensions are not all complete: product variants and business-local barcode uniqueness, configurable tax rules, promotions, purchase orders, stock counts and transfers, returns/refunds, cash-register shifts, loyalty, and the full retail report/export suite still require implementation and workflow tests. The existing POS currently supports cash and pending external-payment records for card/e-wallet; verified external payment processing, bank transfer, and split payments are not implemented. This is not a Supabase migration: tenant authorization remains in the PHP API, and this repository does not have PostgreSQL RLS policies.

### Pharmacy module status

The pharmacy work reuses the same PHP 8.2/PDO SQLite service layer and shared `products`, `sales`, `sale_items`, `inventory_movements`, `inventory_movement_details`, `suppliers`, `businesses`, and `business_members` records. Additive startup migrations create pharmacy product details, medicine batches, batch receipts, immutable batch movements, and sale-to-batch allocations; they do not rewrite or delete existing records. Pharmacy pages are available at `/pharmacy/dashboard`, `/pharmacy/medicines`, `/pharmacy/batches`, and `/pharmacy/pos`.

Implemented and integration-tested:

- Pharmacy-specific product information attached to existing shared products, with role-restricted read/edit APIs and search by generic/brand/ingredient as well as normal product identifiers.
- Batch receiving with expiry and supplier validation, shared stock updates and movement/audit records in one transaction, stable idempotency keys, partial receipts against an existing lot, and immutable receipt/movement history.
- Pharmacy POS FEFO allocation across eligible lots. Expired, recalled, quarantined, damaged, or disposed batches are not allocated. Sale deductions and allocation records commit with the sale; void/cancel restores the original lot quantities without changing a restricted batch's status.
- Prescription-required products are rejected at ordinary POS checkout until an authorized prescription/dispensing workflow exists; this is intentionally fail-closed.
- Pharmacy dashboard metrics/charts use business-scoped persisted sales, products, batch expiry, and stock movements. Expiry periods and sales dates use the business timezone.

Not implemented: prescriptions and partial dispensing, verified professional authorization, purchase-order receiving, controlled-medicine jurisdiction rules, transfers, quarantine/release and disposal workflows, recalls, returns/refunds, pharmacy reports/exports, private prescription document storage, and validated external interaction checking. Location filters are unavailable because this SQLite foundation does not yet model business locations. The pharmacy feature is not a Supabase/PostgreSQL RLS implementation and is not a claim of regulatory compliance; production use requires jurisdiction-specific configuration and qualified professional review.

This is the multi-tenant foundation, not the completed ten-industry product. The specialized pharmacy, clinic, grocery batch/expiry, bakery production, hotel reservation, restaurant kitchen, vehicle rental, computer repair, and coffee recipe workflows, plus their dedicated schemas and reports, remain future phases. SQLite has no PostgreSQL RLS; authorization is enforced in the trusted PHP API. This implementation does not claim regulatory compliance.

## Requirements

- Node.js 20.19+ or 22.12+
- PHP 8.2+
- PHP extensions: `pdo`, `pdo_sqlite`, `session`, `json`, and `mbstring`
- PHP `curl` extension to run the API test script

Confirm PHP extensions with `php -m`. The SQLite database is created automatically on the first API request. By default it lives in a project-specific directory under PHP's system temporary directory, outside both served roots. Set `AUTH_DATABASE_PATH` to a durable, private location when needed.

## Start Locally

Open two terminals in the repository root.

Terminal 1, start the PHP API:

```powershell
cd backend
$env:AUTH_FRONTEND_ORIGIN = 'http://localhost:5173'
$env:AUTH_COOKIE_SECURE = 'false'
php -S localhost:8000 router.php
```

Terminal 2, install and start the frontend:

```powershell
npm install
npm run dev
```

Open the Vite URL, normally `http://localhost:5173`. Vite proxies `/api/*` to `http://localhost:8000`, so the browser uses same-origin requests and session cookies. The root redirects to `/login`.

The frontend's optional `.env` value is documented in `.env.example`. Leave `VITE_API_BASE_URL` blank to use the Vite proxy; set it to an absolute API origin only when intentionally making cross-origin requests. PHP does not load `.env` files automatically: set backend environment variables in the process manager or web server. `backend/.env.example` lists the supported values.

## Point of Sale

Cashier, owner, and administrator memberships in POS-enabled industries can open `/pos`. The POS loads real products and active categories from the active business, supports product/SKU/barcode search, tracks stock in cents/whole units, and provides responsive cart and checkout flows. Cash checkout completes a sale; Card and E-wallet are recorded as **pending** because no payment provider is integrated. Owners and administrators can manage categories from the POS. Product, category, stock, transaction, and cancellation endpoints are protected by server-side membership and role checks; cashiers can access only their own transaction records.

Administrators can open `/dashboard/inventory/products` for catalog management, `/dashboard/inventory/stock` for stock movements, `/dashboard/inventory/suppliers` for supplier records, and `/dashboard/inventory/history` for movement history. Product cost price, reorder level, supplier assignment, status, and stock-at-cost valuation are stored in SQLite. Product edits cannot change stock; new product stock creates an opening-balance record, while subsequent receipts, issues, and adjustments update stock and append matching before/after movement and audit records in one transaction. POS checkout and void/cancel reversals also populate the inventory snapshots. Product and supplier archives preserve historical references and can be restored. The existing database is upgraded additively on API startup.

The API creates the POS tables and indexes on first startup. Business name and currency are read from the active workspace. Configure tax and cashier discount values in the PHP process environment:

- `POS_TAX_BPS` (default `0`, tax in basis points; e.g. `825` is 8.25%)
- `POS_CASHIER_MAX_DISCOUNT_BPS` (default `500`, cashier discount cap in basis points)

For a development-only example catalog, use a disposable local database and explicitly run:

```powershell
$env:APP_ENV = 'development'
php backend/scripts/seed_pos_dev.php
```

The script refuses to run if `APP_ENV` is not `development`; seeded products are clearly marked with `DEV-*` SKUs.

## API

All request and response bodies are JSON. State-changing requests require a session-bound token from `GET /api/csrf`, sent as `X-CSRF-Token`.

- `POST /api/register`: accepts `name`, `email`, `password`, `password_confirmation`, `business_name`, and a supported `industry`; creates the account, workspace, and owner membership atomically. `business_name` defaults to the user's name and `industry` defaults to `retail` for API clients that omit them.
- `POST /api/login`: accepts an email address or username, `password`, and optional `remember_me`; returns the sanitized user and a rotated CSRF token. Invalid credentials receive the same `401` message. Usernames are unique and case-insensitive.
- `GET /api/me`: returns the current sanitized user or `401`.
- `GET|PUT /api/business`: read the active business and memberships or update business name, industry, currency, timezone, and settings. Industry changes require explicit confirmation and never delete records.
- `GET|POST /api/business/members`, `PUT|DELETE /api/business/members/<membership-id>`: list current-business members, add an existing account, change its role, or deactivate its membership. Owner protections prevent removing the final active owner.
- `POST /api/logout`: invalidates the session and clears its cookie.
- `GET /api/csrf`: starts/continues the session and issues the token used by the frontend.
- `GET /api/dashboard/stats`: owner/admin-only aggregate counts scoped to the active business.
- `GET /api/dashboard/registrations`: owner/admin-only daily membership counts for the active business over the last 30 days.
- `GET /api/users?page=1&per_page=10&search=`: owner/admin-only sanitized, searchable, paginated directory scoped to the active business.
- `GET|PUT /api/profile`: read or update only the authenticated user's name and email.
- `GET|PUT /api/settings`: read or update only the authenticated user's theme and email-notification preference.
- `GET /api/pos/config`, `GET /api/products`, and `GET /api/categories`: cashier/admin POS configuration and searchable active catalog.
- `POST|PUT|DELETE /api/products[/<id>]`: administrator product management; created/changed starting stock is recorded as an inventory movement.
- `POST|PUT|DELETE /api/categories[/<id>]`: administrator category management.
- `GET /api/inventory`, `POST /api/inventory/adjustments`: inventory lookup and administrator-only stock adjustments.
- `GET /api/inventory/products`: searchable, filtered, server-paginated product inventory with derived stock status and cost valuation.
- `GET /api/inventory/summary`: administrator-only live product, category, supplier, low-stock, out-of-stock, and valuation metrics.
- `POST /api/inventory/operations`: administrator-only atomic stock receipt, issue, or adjustment with quantity snapshots, reason/reference, supplier, and audit record.
- `GET /api/inventory/history`: administrator-only searchable, filtered, paginated append-only stock history.
- `GET|POST|PUT|DELETE /api/suppliers[/<id>]`, `POST /api/suppliers/<id>/restore`: administrator supplier management; deactivation preserves references and history.
- `POST /api/products/<id>/restore`: administrator product restoration.
- `POST /api/pos/checkout`: server-priced, stock-checked checkout with SQLite transaction rollback and the required `Idempotency-Key` header. Cash is marked paid only when sufficient cash is received; card and E-wallet are pending without a provider.
- `GET /api/pos/transactions`, `GET /api/pos/transactions/<id>`, and `POST /api/pos/transactions/<id>/cancel`: paginated, role-scoped history, receipt/audit details, and authorized cancellation/void with stock restoration.

Login sessions expire after 30 minutes of inactivity; “Remember me” extends the absolute session lifetime and cookie to 30 days. Login and registration have per-IP SQLite rate limits. The `Active users` count means business members with a login in the past 30 days; `New registrations` counts memberships created in the past 7 days. Passwords use `password_hash()` and are verified with `password_verify()`; they are never returned to the frontend or stored in browser storage.

## Roles and Dashboard

The account's legacy `users.role` is not used for business authorization. New registrations receive the `owner` role in `business_members`; operational roles are assigned per business, and the industry-aware membership API limits assignable roles. React route guards improve navigation, while the PHP API independently validates every selected-business membership and role. Profile updates cannot grant permissions.

For existing accounts, business owners can add members from **Dashboard → Users** after the account has registered. The legacy CLI role utility remains available for trusted maintenance:

```powershell
php backend/scripts/promote_admin.php --admin 1 admin@example.com
```

Revoke non-owner administrator/cashier memberships using the same CLI-only script:

```powershell
php backend/scripts/promote_admin.php --revoke 1 admin@example.com
```

The dashboard chart and stat cards use active-business database values, not placeholder analytics. The member directory exposes only accounts attached to the selected business and never selects password hashes.

## API Tests

Keep the PHP server running in Terminal 1, then run from the repository root:

```powershell
npm run test:api
```

The script uses an isolated cookie jar and covers registration, business defaults/configuration, industry-change confirmation, membership and role operations, cross-business membership rejection and category isolation, profile/settings ownership, business-scoped stats/users, login, session lookup, CSRF rejection, logout, and unauthenticated access. Set `AUTH_TEST_BASE_URL` to use a different test server URL and `AUTH_TEST_EMAIL` to pin the test account email.

Run the POS checkout, stock-integrity, and idempotency integration checks with the API server running and an isolated test database:

```powershell
npm run test:pos
```

The POS test creates a temporary administrator and test catalog through SQLite/API, exercises empty and underfunded checkout rejection, oversell protection, pending card status, idempotent replay/conflict, stock decrement/restoration, transaction detail/history, and paid-sale voiding. It also verifies supplier lifecycle, non-stock product edits, inventory receipts/issues, negative-stock prevention, valuation/status filters, movement snapshots, append-only history, and retail dashboard totals, filters, and membership isolation. As with other API tests, configure `AUTH_DATABASE_PATH` identically for the PHP server and test process when using a dedicated test database.

Run the additive migration regression test independently:

```powershell
npm run test:tenant-migration
```

It creates a disposable pre-tenant SQLite schema and verifies that legacy records remain intact and are attached to the one-time legacy workspace.

## Security and Deployment

- Set `APP_ENV=production`, `AUTH_FRONTEND_ORIGIN` to the exact trusted HTTPS frontend origin, and `AUTH_COOKIE_SECURE=true` in production.
- Serve the API over HTTPS. Do not use PHP's built-in server outside local development.
- Set `AUTH_DATABASE_PATH` to a durable path outside every web document root. Restrict filesystem permissions and back up the database securely. The development default is outside the workspace; `backend/database/.htaccess` adds defense in depth if the directory is used manually.
- Configure PHP session storage and garbage collection for the deployment. The application enforces 30-minute idle expiration and 30-day remembered-session expiration itself.
- The built-in SQLite rate limiter is appropriate for a single local instance. Multi-instance deployments need a shared rate-limit store and a database/locking strategy designed for that topology.
- Configure the reverse proxy/web server to serve the frontend and proxy `/api` to PHP under the same site when possible. If deploying cross-origin, keep credentialed CORS restricted to the exact origin and configure cookie `SameSite`/domain policy for that deployment.
- Configure TLS termination, security headers, monitoring, backups, and account recovery/email verification before production. No OAuth provider or password-recovery endpoint is included.
- The PHP development server router returns JSON for unknown paths and never falls through to serving database files. In production, explicitly deny access to backend source and database files at the web-server layer too.

## Troubleshooting

- `could not find driver`: enable `pdo_sqlite` in the active PHP configuration, then restart PHP.
- API connection errors: confirm PHP is listening on port `8000`; check `php -m` and the Vite proxy target.
- CORS `403`: set `AUTH_FRONTEND_ORIGIN` to the exact origin shown in the browser, including scheme and port.
- CSRF `419`: reload the frontend so it can request a new `/api/csrf` token; check that browser cookies are enabled and both requests use the same host (`localhost` versus `127.0.0.1` creates different cookies).
- SQLite permission errors: set `AUTH_DATABASE_PATH` to a directory writable by the PHP process and outside the web root.
- Port already in use: start Vite on another port and update `AUTH_FRONTEND_ORIGIN`; or choose another PHP port and update `vite.config.js`'s proxy target.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the ESLint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and [`typescript-eslint`](https://typescript-eslint.io) in your project.
"# ViteJSBlank" 
"# ViteJSBlank_SQLITE" 
