# Internal WooCommerce Accounting System — AI Planning README

This README is the compact project guide for Claude/Fable. Read it before planning or implementing.

## 1. Purpose

Build an internal accounting and management system for a WooCommerce-based business. The system must calculate real order profit, track purchase costs, manage internal financial records, generate partner/monthly reports, and provide a modern live dashboard.

This is an internal business accounting/reporting system, not a formal tax accounting system at this stage.

## 2. Data Sources

Real production data must come from the mirrored hub:

```txt
behdashtik-hub-main/
```

The accounting system must not query the production WooCommerce site directly.

The development WooCommerce site may be used only for discovery, mapping analysis, and safe tests:

```txt
dev.behdashtik.ir
```

Dev data must never create real accounting records or real reports.

## 3. Hard Rules

- Currency is **Toman everywhere**.
- Business timezone is `Asia/Tehran`.
- User-facing dates must be Jalali/Shamsi.
- Store/business display name must be configurable.
- The app name must not be tied to the store name.
- Current phase must not send prices, stock, wholesale prices, or orders to WooCommerce.
- Wholesale prices are internal only.
- Sensitive financial data must not leak outside the accounting system.
- Public APIs, outgoing webhooks, public logs, public exports, Telegram/SMS, or WooCommerce updates must not expose purchase cost, profit, margins, payroll, banks, loans, cheques, or partner reports.
- General logs must not include sensitive financial values. Use protected audit logs when needed.

## 4. Claude/Fable Workflow

- Start in Plan Mode.
- Inspect the repo and hub structure before assuming data shapes.
- Prefer configurable mappings over hard-coded business rules.
- Use Interview Mode only for heavy decisions.
- Do not ask the user about minor UI/naming/layout details unless they affect business meaning or financial correctness.
- Every financial number must be explainable, traceable, and auditable.

## 5. Accounting Core

Implement a real internal accounting foundation, including:

- Chart of accounts
- Double-entry journal logic
- Bank/cash accounts
- Parties
- Receivables/payables
- Sales revenue
- Cost of goods sold
- Shipping income/expense
- Platform/channel fees
- Expense categories and cost centers
- Payroll basics
- Loans
- Cheques
- Manual adjustments
- Period locking
- Opening balances
- Audit trail
- Reversal/voiding instead of unsafe deletion

## 6. Order Profit Rules

- Normal WooCommerce orders become financially valid when completed.
- Platform-specific orders become financially valid based on configurable status mapping, such as shipped/sent to customer.
- Pending-payment orders must not create final profit.
- Cancelled, failed, refunded, partially refunded, returned, and adjusted orders must be handled explicitly.
- Missing purchase cost or missing Cost Mapping must never be treated as zero.
- Incomplete orders must go to review queues.

Profit must be explainable per order and per item: gross sale, discounts, net sale, product cost, cost source, shipping charged, real/default shipping cost, platform/channel fees, payment gateway fee if available, gross profit, operational profit, final impact, and profit status.

## 7. Product Cost Mapping

WooCommerce remains the operational sales inventory system.

The accounting system reads product price/stock from the hub but does not manage sales inventory.

Use Cost Mapping for profit discovery:

- WooCommerce product/variation → Cost Item
- Cost Group for shared costs
- Latest purchase cost by default
- Cost multiplier for packs or multi-unit products
- Optional cost formula only when needed for cost calculation
- Mapping status

Simple vs variable WooCommerce products must not break accounting. Each product or variation must map to a Cost Item.

Do not implement bundle management in the current scope.

## 8. Purchase Costs

Support purchase invoices, suppliers, purchase date, quantity, received quantity, partial delivery, supplier cancellation/non-delivery, notes, purchase shipping cost, landed unit cost, purchase-cost history, Excel import for historical costs, and manual cost entry.

Default cost basis is latest purchase price.

Purchase shipping allocation:

- Default: allocate by quantity.
- Allow manual override.

If purchase cost is corrected later, do not silently rewrite old reports. Provide controlled recalculation options with audit logs.

## 9. Wholesale Price

Wholesale price is in current scope.

For each product/Cost Item, support latest purchase cost, retail/site sale price, internal wholesale price, retail profit/margin, wholesale profit/margin, and wholesale status.

For variable products, a shared wholesale price for the product/group is enough by default. Per-color/per-number wholesale prices are not required unless explicitly configured.

Wholesale rules may state that a wholesale buyer must buy at least one unit from each required number/color/model.

Wholesale data must remain internal and must not be sent to WooCommerce.

## 10. Product Page

The internal product page should show product name, WooCommerce ID/variation ID, SKU, GTIN/UPC/EAN/ISBN, simple/variable type, current site stock from hub, current sale price, retail price, wholesale price, latest purchase cost, retail profit/margin, wholesale profit/margin, Cost Mapping status, price history, stock sync history, last sale, and last purchase.

Keep access control simple, but prevent sensitive data leakage outside the accounting system.

## 11. Dynamic Sales Channels

Sales channels must be **dynamic and data-driven**.

Do not hard-code a fixed channel list.

Fable must inspect real hub/dev order metadata and discover available source fields, source values, referrers, campaign fields, platform markers, and related metadata.

The system must include a configurable channel registry and source-mapping layer:

- Store raw source data from each order.
- Normalize raw values through configurable mappings.
- Auto-detect new/unmapped source values.
- Never fail or lose an order because a new source appears.
- Queue unknown/new sources for review.
- Allow admins to map a raw source to an existing channel or create a new channel.
- Allow authorized recalculation/reporting after mapping changes.
- Keep channel reports generic so future sources work without code changes.

Example: if a future order source appears as `gemini`, `gemeni`, or any other new value, the system must store it, classify it safely as unknown/new until mapped, show it in review, and include it in reports with a fallback label.

## 12. Channel Costs and Enrichment

Channel behavior must be configuration-driven.

Supported cost/enrichment models should include:

- Manual period costs
- Wallet/top-up costs
- Per-order commission from order metadata
- API enrichment for settlement, installment, debt, or status data when configured
- No direct cost

For wallet/top-up channels, period cost equals the sum of top-ups in that Jalali period.

For metadata-commission channels, commission should be read from order metadata when available. Missing commission should create a warning, not a crash.

For API-enriched channels, API data may enrich debt, settlement, installment, or status data, but the hub remains the primary order source unless explicitly changed later.

## 13. Shipping

Current implementation:

- Real shipping cost is manually editable per order.
- A default shipping cost setting must exist.
- If real shipping cost is missing and customer shipping charge is not zero, use customer-paid shipping as temporary/default cost basis.
- Future enhancement may import postal PDFs, extract tracking/cost data, SMS tracking codes, complete orders, and update accounting shipping costs automatically.

Reports must separate shipping charged to customer, real/default shipping cost, shipping difference, and shipping impact on profit.

## 14. Credit Customers and Receivables

Support internal credit orders where a customer takes goods now and pays later.

Track debt by order, partial payments, remaining balance, payment account, receivable aging/overdue state, and customer credit balance if they overpay.

## 15. Expenses, Payroll, Loans, Cheques

Support expenses, categories, cost centers, payment account, receipts/attachments, whether an expense affects partner profit, current vs capital/asset expense, employees, payroll, employee advances/debts, loans, installments, cheques receivable/payable, due dates, and alerts.

Suggested cost centers include warehouse, logistics, programming/development, AI tools, marketing, management, finance/accounting, HR, and purchasing.

## 16. Partner / Periodic Reports

Partner reports are a core output.

Reports must use Jalali periods and Tehran timezone.

Show gross sales, net sales, completed/accounted sales, order count, average order value, gross profit, operational profit, net period profit, profit percentages, cash collected, receivables, payables, bank/cash balances, inventory value for visibility, product/channel performance, shipping, fees, expenses by category/cost center, payroll, loans/cheques summary, lost sales when data exists, returns/refunds/cancellations, incomplete-data warnings, and adjustments from previous periods.

Report states:

- Draft
- Needs review
- Final
- Corrected/adjusted

Before finalizing, show a readiness checklist: missing costs, missing mappings, missing shipping costs, unknown sources, sync errors, etc.

Finalized reports must be snapshotted. Later corrections must be handled as adjustments, not silent changes.

## 17. Dashboard

The dashboard is a major product area.

It must be responsive, live or near-live, modern, fast, and useful for daily management.

Use professional UI/chart libraries when helpful. Charts do not need to be fully RTL if that hurts quality. Prioritize correct data, readability, modern visuals, interaction, and Jalali/Persian display where appropriate.

Dashboard should show KPI cards, sales/profit trends, dynamic channel comparison, channel cost/profitability, shipping analysis, expenses, cash/bank overview, receivables/payables, product performance, low-margin/loss-making products, missing Cost Mapping, sync health, alerts, review tasks, recent price/stock changes, and unknown/new source alerts.

## 18. Product Price and Stock Sync

The hub can apply product updates.

The accounting system must update displayed product sale price and stock when hub/webhook data changes.

Track history for sale price, purchase cost, wholesale price, hub stock, Cost Mapping, and default shipping cost changes.

For price/stock changes, log old value, new value, product/variation ID, source, timestamp, and correlation ID in protected audit/sync logs.

## 19. API and Mobile Future

The system must be API-ready, but the mobile app is not part of current implementation.

Future mobile app may scan barcode/GTIN/UPC/EAN/ISBN, show product prices, show wholesale price, build invoice/quote, select customer, register payment, and later send orders to WooCommerce.

Current phase must not implement mobile app or WooCommerce order creation.

APIs must be private/authenticated and must not expose sensitive data to unauthorized users.

## 20. CLI

Include CLI tools for AI/developer operations:

- Health check
- Hub connectivity check
- Inspect sample order
- Inspect product mapping
- Inspect discovered sources/channels
- Sync selected order
- Recalculate order profit
- List missing costs
- List unmapped products
- Import purchase-cost Excel with dry-run
- Validate accounting integrity
- Rebuild reports/read models
- Show/retry sync errors

CLI output should support human-readable and JSON modes.

## 21. Sync and Reliability

Use safe sync patterns:

- Idempotent processing
- External ID mapping
- Webhook/event queue
- Retry
- Failed jobs
- Dead-letter queue
- Correlation ID
- Sync status
- Manual retry
- No duplicate orders
- No duplicate accounting entries
- Detect changed order/product metadata
- Detect new/unmapped source values

Dashboard/CLI must show sync health, last successful sync, failed webhook count, pending jobs, hub availability, and new/unmapped source count.

## 22. Review Queues and Fast Forms

Create a needs-review area for completed orders without cost, products without Cost Mapping, products without wholesale price, unknown/new order sources, missing real/default shipping data, unpaid credit balances, supplier delivery issues, sync failures, and low-margin/loss-making products.

Provide fast forms for expenses, real shipping cost, channel top-up/cost, purchase cost, wholesale price, customer payment, supplier payment, and source-to-channel mapping.

## 23. Attachments

Allow protected attachments for purchase invoices, payment receipts, shipping receipts, channel/top-up receipts, payroll receipts, programming/AI expense receipts, cheques, loans, and expense documents.

## 24. Testing Requirements

Tests are mandatory, especially because AI will help build the system.

Required tests include:

- Simple product price/stock sync
- Variable product/variation price/stock sync
- Fake dev orders without real accounting impact
- Completed order profit calculation
- Pending order exclusion
- Platform/channel commission metadata
- Dynamic channel discovery
- Unknown source fallback behavior
- New source mapping and report recalculation
- Channel top-up/cost reporting
- Missing cost handling
- Cost multiplier
- Wholesale profit/margin
- Purchase cost import from Excel
- Shipping fallback logic
- Credit order partial settlement
- Partner report readiness/finalization
- Webhook retry/idempotency
- No dev data entering real accounting

## 25. Current Scope vs Future Scope

Current scope:

- Accounting core foundation
- Hub-based order/product sync
- Product price and stock display sync
- Product price history
- Cost Mapping
- Purchase cost tracking
- Latest-purchase-cost profit calculation
- Wholesale price management
- Order profit calculation
- Dynamic sales-channel discovery/reporting
- Configurable channel cost models
- Manual shipping cost/default shipping logic
- Credit customers/receivables
- Bank/cash accounts
- Expenses/cost centers
- Payroll basics
- Loans
- Cheques
- Partner reports
- Live responsive dashboard
- Logs/audit
- CLI
- API-ready architecture
- Tests

Future, not current scope:

- Mobile app
- Barcode scanning app workflow
- Creating invoices/orders from mobile
- Sending new orders to WooCommerce
- Automatic postal PDF import and SMS tracking workflow
- Budgeting module
- Advanced bank reconciliation automation

## 26. Final Guidance

Keep the implementation serious but not unnecessarily complicated.

Prefer configuration over hard-coding for statuses, channels, source mapping, cost centers, reports, and thresholds.

Every financial number must be explainable, traceable, and auditable.

The channel system must never break when a new source appears. New sources must be stored, surfaced, reviewable, and reportable with a safe fallback until mapped.

Optimize for correctness, maintainability, testability, and future debugging.
