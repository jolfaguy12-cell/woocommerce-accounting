# Internal WooCommerce Accounting System — AI Planning README

This README defines the required behavior, boundaries, and planning rules for building the internal accounting system. Claude/Fable must read this before planning or implementing.

## 1. Project Purpose

Build a strong internal accounting and financial dashboard system for a WooCommerce-based business.

This is not only an order-profit dashboard. It must become an internal accounting, profit-discovery, reporting, cost-tracking, and management system for:

- WooCommerce mirrored orders
- Product cost and profit calculation
- Purchase cost tracking
- Partner/monthly reports
- Bank/cash tracking
- Expenses
- Payroll
- Loans
- Cheques
- Credit customers
- Basalam, Torob, Google and other sales channels
- Live responsive dashboard
- Future API/mobile use

The system is for internal business accounting and management reporting, not formal tax accounting at this stage.

## 2. Data Sources and Environments

### Main data source

The accounting system must not connect directly to the production WooCommerce website.

The main source of real business data is the existing mirrored hub project:

```txt
behdashtik-hub-main/
```

The hub already mirrors website/order/product data and exposes data through webhook/API mechanisms.

### Development site

The development WooCommerce site may be used only for discovery, sampling, mapping analysis, and testing structure:

```txt
dev.behdashtik.ir
```

Rules:

- Do not generate real accounting records from dev data.
- Do not include dev orders in real financial reports.
- Use dev only to inspect order metadata, product/variation structure, source fields, webhook behavior, and mapping requirements.
- Any tests against dev must run in sandbox/test mode.

## 3. Non-Negotiable Rules

- Currency is **Toman everywhere**, including the website, imports, reports, and dashboard.
- Business timezone is `Asia/Tehran`.
- User-facing dates must be Jalali/Shamsi.
- Internal timestamps may be stored in UTC, but reports must use Tehran/Jalali period boundaries.
- The application name must not be tied to the store name; store/business name must be configurable.
- Production accounting data comes from the hub, not from dev WooCommerce.
- The accounting system must not send prices, stock, wholesale prices, or orders to WooCommerce in the current phase.
- Wholesale data is internal only and must not be pushed to the website.
- Profit, purchase cost, margins, partner reports, payroll, bank data, cheques, loans, and sensitive financial data must not leak outside the accounting system.
- Public APIs, outgoing webhooks, public logs, public exports, Telegram/SMS messages, or WooCommerce updates must not expose sensitive financial data.
- General app logs must not contain sensitive financial numbers. Sensitive audit data may exist only in protected internal audit logs.

## 4. How Claude/Fable Must Work

Claude/Fable must start in Plan Mode.

Before implementation, inspect the repository structure and existing hub capabilities. Then produce an implementation plan.

Use Interview Mode only for major business or architectural decisions. Do not interrupt for small details that can be safely inferred or implemented with configuration.

Ask questions only when a wrong choice could damage accounting accuracy, security, reporting, or future extensibility.

When useful, research existing open-source systems, dashboard patterns, accounting/reporting UI, chart libraries, and admin components for inspiration. Respect licenses. Do not copy code or UI blindly.

## 5. Accounting Core

The system must include a real internal accounting foundation, not only calculated fields.

Support:

- Chart of accounts
- Double-entry journal entries
- Bank/cash accounts
- Receivables
- Payables
- Parties
- Sales revenue
- Cost of goods sold
- Shipping income/expense
- Platform/channel fees
- Expense categories
- Cost centers
- Payroll expenses
- Loans
- Cheques
- Manual adjustments
- Period locking
- Opening balances
- Audit trail
- Reversal/voiding instead of unsafe deletion

Every important number in reports must be traceable to source records.

## 6. Order Profit Rules

Profit is recognized when the order becomes financially valid:

- Normal WooCommerce order: when status is completed.
- Basalam-related order: when Basalam status indicates the order has been sent/shipped to the customer.
- Pending-payment orders must not produce final profit.
- Cancelled, failed, refunded, partially refunded, returned, and adjusted orders must be handled explicitly.

Profit must be explainable per order and per item:

- Gross sale
- Discounts
- Net sale
- Product cost
- Cost source
- Shipping charged to customer
- Real shipping cost
- Basalam commission
- Payment gateway fee if available
- Other direct order costs
- Gross profit
- Operational profit
- Net profit impact
- Profit status: final, incomplete, estimated, adjusted

If purchase cost or product cost mapping is missing, do not silently treat cost as zero. Mark the order as incomplete and show it in review queues.

## 7. Product Cost Mapping

WooCommerce inventory remains the main operational inventory for sales.

The accounting system does not become the main sales inventory manager. It only reads and displays stock from the hub and uses product/cost mapping for profit discovery.

Use a cost-mapping model:

- WooCommerce product or variation
- Cost Item
- Cost Group
- Purchase cost
- Cost multiplier
- Optional cost formula for complex cost calculation only
- Cost mapping status

WooCommerce simple vs variable products must not break accounting. Each product or variation must map to a Cost Item.

Examples:

- Single item sale: multiplier `1`
- Pack of 12 sold as one product: multiplier `12`
- Product variants/colors can share the same cost through a Cost Group.
- Variant-specific cost override is optional, not required by default.

Do not model this as a bundle feature in the current scope. Bundle management is out of current scope.

## 8. Purchase Costs

Default cost basis is latest purchase price.

The system must support:

- Purchase invoices
- Supplier
- Purchase date
- Purchased quantity
- Received quantity
- Partial delivery
- Supplier cancellation/non-delivery
- Notes
- Purchase shipping cost
- Landed unit cost
- Purchase-cost history
- Excel import for historical purchase costs
- Manual cost entry when needed

Purchase shipping allocation:

- Default: allocate by quantity.
- Allow manual allocation override.

If purchase cost is corrected later, do not silently rewrite old reports. Provide controlled recalculation options with audit logs.

## 9. Wholesale Price

Wholesale price is part of the current implementation scope.

For each product/Cost Item, support:

- Latest purchase cost
- Retail/site sale price
- Internal wholesale price
- Retail profit
- Retail margin
- Wholesale profit
- Wholesale margin
- Wholesale status: missing, ok, low-margin, loss-making

For variable products:

- A single wholesale price for the product/group is enough by default.
- No need to define separate wholesale price for every color/number unless manually overridden.
- Wholesale rule can state that the wholesale buyer must buy at least one unit from each required number/color/model.
- Wholesale pricing and rules are internal only and must not be sent to WooCommerce.

## 10. Product Display Page

The accounting product page must show, for internal users:

- Product name
- WooCommerce ID / variation ID
- SKU
- GTIN / UPC / EAN / ISBN when available
- Simple/variable type
- Current site stock from hub
- Current site sale price
- Retail price
- Wholesale price
- Latest purchase cost
- Retail profit/margin
- Wholesale profit/margin
- Cost mapping status
- Price history
- Stock sync history
- Last sale details
- Last purchase details

Keep access control simple, but prevent data leakage outside the accounting system.

## 11. Sales Channels and Attribution

Sales channels must be discovered from real order metadata by inspecting hub/dev data.

Do not hard-code all raw source values in this README. Fable must analyze orders and create configurable mappings.

Required normalized channels include at least:

- Direct/WooCommerce
- Torob
- Google
- Basalam
- Manual/internal sale
- Credit sale
- Unknown
- Other

Keep raw source values for audit/debugging, and store normalized channel mapping.

Unknown/unmapped sources must appear in a review queue.

## 12. Torob

Torob is a sales acquisition channel, not an independent order source.

- Torob orders come through WooCommerce/hub.
- Torob source mapping must be discovered from order metadata.
- Torob is charged through wallet/top-up payments.
- Users manually register Torob top-ups in the system.
- Monthly Torob cost = sum of Torob top-ups in that Jalali period.
- Torob report must show sales, profit, shipping, costs, top-ups, and final channel profit.

## 13. Google

Google orders must be reported as a sales channel when detectable from order metadata.

Google reports should show:

- Order count
- Sales
- Cost of goods
- Shipping
- Profit
- Registered Google-related costs if any
- Final channel profitability

Fable should discover exact Google source fields from available order data.

## 14. Basalam

Basalam order commission is available in WooCommerce order metadata and should be used for order profit calculation.

Basalam API may be used as enrichment/reconciliation, not as the primary order source.

Use Basalam API when useful for:

- Installment/credit state
- Remaining customer debt
- Paid amount
- Settlement state
- Shipment/sent status
- Reconciliation against local hub data

## 15. Shipping Cost

Current implementation:

- Real shipping cost is manually editable per order.
- A default shipping cost setting must exist.
- If real shipping cost is not entered and customer shipping charge is not zero, use the customer-paid shipping amount as the temporary/default cost basis.
- Later enhancement: import postal PDFs, extract tracking/cost data, SMS tracking codes, complete orders, and update accounting shipping costs automatically.

Reports must separate:

- Shipping charged to customer
- Real/default shipping cost
- Shipping difference
- Shipping cost impact on profit

## 16. Credit Customers and Receivables

Support internal credit orders:

- Customer takes goods now and pays later.
- Track debt by order.
- Allow partial payments.
- Track remaining balance.
- Track payment account.
- Show receivables by customer and aging/overdue status.
- Allow customer credit balance if they overpay.

## 17. Expenses, Cost Centers, Payroll, Loans, Cheques

Support:

- Expense registration
- Expense category
- Cost center
- Payment account
- Attachments/receipts
- Whether expense affects partner profit report
- Current expense vs capital/asset expense
- Employee profiles
- Payroll payments
- Employee advances/debts
- Loans and installment schedules
- Cheques receivable/payable
- Due date alerts
- Telegram/internal notifications when appropriate

Suggested high-level cost centers include warehouse, logistics, programming/development, AI tools, marketing, management, finance/accounting, HR, and purchasing.

## 18. Partner / Periodic Report

Partner reports are a core output, not an afterthought.

Reports must use Jalali periods and Tehran timezone.

Show at least:

- Gross sales
- Net sales
- Completed/accounted sales
- Order count
- Average order value
- Gross profit
- Operational profit
- Net period profit
- Profit percentages
- Cash collected
- Remaining receivables
- Payables
- Bank/cash balances
- Inventory value for visibility
- Product/channel performance
- Torob/Google/Basalam performance
- Shipping charged vs real cost
- Fees/commissions
- Expenses by category and cost center
- Payroll
- Loans and cheques summary
- Lost sales when data exists
- Returns/refunds/cancellations
- Incomplete-data warnings
- Adjustments from previous periods

Report states:

- Draft
- Needs review
- Final
- Corrected/adjusted

Before finalizing, the system must show a readiness checklist: missing costs, missing mappings, missing shipping costs, unknown sources, sync errors, etc.

Finalized reports must be snapshotted. Later corrections must be handled as adjustments, not silent changes.

## 19. Dashboard

The dashboard is a major product area.

It must be:

- Responsive
- Live or near-live
- Modern
- Fast
- Useful for daily management
- Built with professional UI components and chart libraries when useful

Charts do not need to be fully RTL if that reduces quality. Prioritize readability, modern visual quality, interaction, and correct Persian/Jalali labels.

Dashboard should include:

- KPI cards
- Sales/profit trends
- Channel comparison
- Torob performance
- Google performance
- Basalam performance
- Shipping analysis
- Expense charts
- Cash/bank overview
- Receivables/payables
- Product performance
- Low-margin/loss-making products
- Missing cost mapping
- Sync health
- Alerts
- Tasks needing review
- Recent price/stock changes

## 20. Product Price and Stock Sync

The hub can apply product updates.

The accounting system must update displayed product sale price and stock when hub/webhook data changes.

Track history:

- Sale price changes
- Purchase cost changes
- Wholesale price changes
- Stock changes from hub
- Cost mapping changes
- Default shipping cost changes

For price changes, log old value, new value, product/variation ID, source, timestamp, and correlation ID in protected audit/sync logs.

## 21. API and Mobile Future

The system must be API-ready, but the mobile app is not part of current implementation.

Future mobile app may:

- Scan barcode/GTIN/UPC/EAN/ISBN
- Show product prices
- Show wholesale price
- Build invoice/quote
- Select customer
- Register payment
- Later send order to WooCommerce

Current phase must not implement mobile app or WooCommerce order creation, but data/services should not block this future.

API must be private/authenticated and must not expose sensitive data to unauthorized users.

## 22. CLI

Include a CLI for AI/developer operations.

Useful commands:

- Health check
- Hub connectivity check
- Inspect sample order
- Inspect product mapping
- Sync selected order
- Recalculate order profit
- List orders with missing cost
- List unmapped products
- Import purchase-cost Excel with dry-run
- Validate accounting integrity
- Validate ledger balance
- Rebuild reports/read models
- Show sync errors
- Retry failed jobs

CLI output should support human-readable and JSON modes.

## 23. Sync, Webhooks, Reliability

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

Dashboard/CLI must show sync health:

- Last successful sync
- Last product update
- Last order update
- Failed webhook count
- Pending jobs
- Hub availability

## 24. Review Queues and Fast Forms

Create a “needs review” area for:

- Completed orders without cost
- Products without Cost Mapping
- Products without wholesale price
- Unknown order sources
- Missing real/default shipping data
- Credit customers with unpaid balance
- Supplier partial delivery issues
- Sync failures
- Low-margin or loss-making products

Provide fast forms for common actions:

- Register expense
- Register real shipping cost
- Register Torob top-up
- Register purchase cost
- Register wholesale price
- Register customer payment
- Register supplier payment

## 25. Attachments

Allow protected attachments for:

- Purchase invoices
- Payment receipts
- Shipping receipts
- Torob top-up receipts
- Payroll receipts
- Programming/AI expense receipts
- Cheques
- Loan documents
- Expense documents

## 26. Testing Requirements

Testing is mandatory, especially because AI will help build the system.

Required tests include:

- Simple product sync: price and stock
- Variable product sync: variation price and stock
- Fake dev order affecting simple products
- Fake dev order affecting variations
- Completed order profit calculation
- Pending order not finalized
- Basalam commission metadata
- Torob channel mapping and top-ups
- Google channel mapping
- Missing cost handling
- Cost multiplier
- Wholesale profit/margin
- Loss-making wholesale price
- Purchase cost import from Excel
- Real/default shipping logic
- Credit order and partial settlement
- Expense and cost center reporting
- Partner report readiness checklist
- Final report snapshot and later adjustment
- Webhook retry/idempotency
- No dev data entering real accounting

## 27. Current Scope vs Future Scope

### Current implementation scope

- Accounting core foundation
- Hub-based order/product sync
- Product price and stock display sync
- Product price history
- Cost Mapping
- Purchase cost tracking
- Latest-purchase-cost profit calculation
- Wholesale price management
- Order profit calculation
- Basalam commission from metadata
- Optional Basalam enrichment planning
- Torob/Google/channel reporting
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

### Future, not current implementation

- Mobile app
- Barcode scanning app workflow
- Creating invoices/orders from mobile
- Sending new orders to WooCommerce
- Automatic postal PDF import and SMS tracking workflow
- Budgeting module
- Advanced bank reconciliation automation

## 28. Final Guidance

Keep the implementation serious but not unnecessarily complicated.

Prefer configuration over hard-coding when dealing with statuses, channels, source mapping, cost centers, reports, and thresholds.

Every financial number must be explainable, traceable, and auditable.

Do not optimize only for code generation. Optimize for correctness, maintainability, testability, and future debugging.
