# CLAUDE.md

Internal WooCommerce accounting/reporting system (not tax accounting). The full authoritative spec is **README.md** — read it before planning or implementing. If this file and README ever disagree, README wins.

## Stack & Commands

- Laravel 12 + Inertia/React (shadcn, Vite), MySQL 8 (`woocommerce_accounting`), Pest, Pint. Domain code lives in `app/Domain/{Accounting,Orders,Channels,Costing,Products,Receivables,Expenses,Reports,Sync}`.
- Money is integer Toman (BIGINT); all journal writes go through `App\Domain\Accounting\Services\JournalPoster` (balanced, idempotent, period-lock aware — never insert journal rows directly).
- Test: `./vendor/bin/pest` · Lint: `./vendor/bin/pint --dirty` · Frontend: `npm run build` (dev: `composer dev`). Tests must stay green before moving on (TDD).
- Implementation plan/milestones: `/root/.claude/plans/please-enter-plan-mode-cozy-bonbon.md`.
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
