# CLAUDE.md

Internal WooCommerce accounting/reporting system (not tax accounting). The full authoritative spec is **README.md** — read it before planning or implementing. If this file and README ever disagree, README wins.

## Stack & Commands

- Laravel 12, MySQL 8 (`woocommerce_accounting`), Pest, Pint. Domain code lives in `app/Domain/{Accounting,Orders,Channels,Costing,Products,Receivables,Expenses,Reports,Sync}`.
- **Frontend is mid-migration, off Inertia/React onto Blade + Alpine.js** (the pre-installed TailAdmin template, `resources/views/**`). Interactivity is plain GET/POST forms with full page reloads — no fetch/AJAX, Alpine only for cosmetic client-side bits (dropdowns, modals, column toggles). **Never build a new page in React** (`resources/js/pages/**`); it's being phased out page by page. Migrated to Blade: dashboard shell, orders, products, tools, settings, warehouse (packaging cost), notifications (notes). Still Inertia/React (do not extend, only migrate): review, fast-forms, reports, users. Staged plan: `/root/.claude/plans/glistening-giggling-kernighan.md`.
- **Data tables** (orders, products, invoices, any list view): always follow the shadcn/ui Data Table pattern, built with Blade + Alpine (never the React component) — rounded bordered card, search input + status-filter dropdown + column-visibility dropdown in the toolbar, sortable column headers with arrow icons, checkbox row selection (with header indeterminate state), status badges (dot + label, semantic color), per-row ⋮ action menu, footer with "N of M rows selected" + pagination. RTL, IRANSansX font, Persian digits. ID and amount columns are center-aligned (not left/right) to keep header and values lined up. The ⋮ row-action menu must open toward the reading direction it has room in (not off the edge of the viewport/table) — verify this per table layout rather than copying a fixed side. Approved by user 2026-07-11; reference implementation: `https://claude.ai/code/artifact/b3db793e-bd90-4ce7-a9f0-d868cee16ad1`.
- Money is integer Toman (BIGINT); all journal writes go through `App\Domain\Accounting\Services\JournalPoster` (balanced, idempotent, period-lock aware — never insert journal rows directly).
- Roles (Spatie permission): `admin`, `accountant`, `warehouse`, `partner_viewer`. Convention in `routes/web.php`: reads open to `admin|accountant|warehouse`; financial mutations (shipping/packaging cost, recalc, cost/wholesale mapping) `admin|accountant` only; users/tools/settings/warehouse-config `admin` only; reports readable by `admin|accountant|partner_viewer`, finalize `admin` only.
- Test: `./vendor/bin/pest` · Lint: `./vendor/bin/pint --dirty` · Frontend: `npm run build` (dev: `composer dev`). Tests must stay green before moving on (TDD).
- Original build plan/milestones: `/root/.claude/plans/please-enter-plan-mode-cozy-bonbon.md`.
- `/dashboard` (`DashboardController` + `DashboardMetricsService`) is reconnected to real data for: recent orders, the monthly order-count chart, and 3 of 8 KPI cards (new customers, gross sales, stock on hand) — each "vs last month" via `monthly_dashboard_snapshots` (past closed months frozen, current month always live). The other 5 KPI cards and the `monthly-target`/`statistics-chart` widgets are still static placeholders, not yet scheduled.
- This server hosts many other services. Before binding ANY port (artisan serve, Vite, queues, docker), check it is free (`ss -tlnp | grep <port>`) and never reuse a port already in use.

## Data Sources

- Real data comes only from the mirrored hub: `behdashtik-hub-main/`. Never query the production WooCommerce site directly.
- `dev.behdashtik.ir` is for discovery and safe tests only. Dev data must never create real accounting records or reports.

## Non-Negotiable Rules

- Currency: Toman everywhere. Timezone: `Asia/Tehran`. User-facing dates: Jalali/Shamsi.
- Store display name is configurable; the app name is not tied to the store name.
- Current phase never writes to WooCommerce (no prices, stock, wholesale prices, or orders).
- Wholesale prices are internal only.
- Sensitive financial data (purchase cost, profit, margins, payroll, banks, loans, cheques, partner reports) must never leak via public APIs, webhooks, logs, exports, or WooCommerce. General logs must not contain sensitive values — use protected audit logs.
- Missing purchase cost or missing Cost Mapping is never treated as zero — such orders go to review queues.
- Every financial number must be explainable, traceable, and auditable. Use reversal/voiding and adjustments, never silent edits or deletion.
- Product-page cost/wholesale entry (`ProductController::storeCost`/`setWholesale`) is profit-discovery data only — it feeds `CostResolver`/`ProfitEngine` so order profit/loss can be computed, and must never create a `Party`, `PurchaseInvoice`, or `JournalEntry`. Real purchases (a real supplier, a real accounts-payable entry) are recorded exclusively through `/new-buy-order` (`PurchaseInvoiceController` + `PurchaseInvoiceService`). Don't blur this boundary again.

## Architecture Principles

- Prefer configurable mappings over hard-coded business rules (statuses, channels, source mapping, cost centers, thresholds).
- Sales channels are dynamic and data-driven — never hard-code a channel list. Unknown sources are stored, queued for review, and reported with a fallback label; they must never break processing.
- Sync must be idempotent: external ID mapping, retry, dead-letter queue, correlation IDs, no duplicate orders or accounting entries.
- Tests are mandatory for all financial logic (see README §24 for the required list).

## Workflow

- Start in Plan Mode. Inspect the repo and hub structure before assuming data shapes.
- Ask the user (Interview Mode) only for heavy decisions: accounting correctness, profit recognition, report definitions, cost calculation, period locking, sensitive-data exposure, sync architecture, major DB modeling, historical recalculation, or sending data to external systems. Present the trade-off and recommend a default.
- Do not ask about minor UI/naming/layout details unless they affect business meaning or financial correctness.
- Keep output concise; the user optimizes for speed, accuracy, and low token usage.
