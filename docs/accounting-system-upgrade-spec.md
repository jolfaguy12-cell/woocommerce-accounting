Implement the accounting-system upgrade in one controlled Claude coding session, but execute it as isolated phases with separate commits, targeted verification, and a final production deployment. Extend the existing architecture; do not create a parallel ledger, balance engine, transaction engine, or duplicate accounting subsystem.

Core constraints

- `journal_lines` must remain the single source of truth for every financial balance.
- Every financial posting must go through the existing `JournalPoster::post()` and `JournalPoster::reverse()` mechanisms.
- Never store derived balances in new columns.
- Every multi-line financial operation must be atomic inside one database transaction.
- Every operation must use an idempotency key and prevent duplicate posting.
- Posted journal entries and journal lines must remain immutable.
- Corrections must use reversal entries, never destructive editing or deletion.
- Preserve accounting-period locks, current authorization conventions, audit history, attachments, creator attribution, and the enforced morph map.
- Reuse existing services, models, TableQuery/pro-table components, modals, tabs, cards, RTL layouts, and shared design-system components.
- Do not introduce page-specific UI hacks.
- Do not add manual debit/credit fields to the normal user-facing workflow. The system must derive accounting entries from the selected business operation.

Phase 1 — Unified multi-role Party identity

Convert Party into one shared identity that can hold multiple simultaneous roles.

Required behavior:

- One real person or company must have one Party record.
- One Party may simultaneously be a customer, supplier, employee, partner, or other counterparty.
- Do not model lender and borrower as permanent Party roles. Derive those states from receivable/payable loan contracts.
- Keep `User` as the authentication and authorization identity.
- Allow an optional User-to-Party association without merging the two concepts.
- Preserve every current Party ID and all existing foreign keys.

Create an additive `party_roles` table with:

- party_id
- role as an indexed string
- is_active
- activated_at
- deactivated_at
- activated_by
- deactivated_by
- timestamps
- unique constraint on party_id + role

Use a domain enum or centralized validation for allowed role values, but do not use a database enum.

Add centralized Party methods and scopes:

- roles()
- activeRoles()
- hasRole()
- withRole()
- activateRole()
- deactivateRole()

Role deactivation must never delete the Party or historical records. Log all role activation and deactivation changes through the existing activity-log infrastructure.

Backfill every current `parties.type` value into `party_roles` without changing Party IDs.

Do not use an observer to pretend that one legacy `parties.type` value can represent multiple roles.

Migration sequence:

1. Add and backfill `party_roles`.
2. Convert every read, validation rule, query, route guard, resolver, command, import, factory, seeder, and test that depends on `parties.type`.
3. Convert `CustomerResolver` and WooCommerce synchronization before enabling assignment of a second role.
4. Verify there are no remaining runtime dependencies on `parties.type`.
5. Enable multi-role management.
6. Remove `parties.type` only in a separate final migration and separate commit after all dependencies are confirmed removed.

Do not automatically merge existing Party records. Duplicate detection must only produce review suggestions.

Identity and duplicate controls

Support person and company identities without relying on names or phone numbers as definitive identity keys.

Add the minimal reusable identity structure required for:

- person/company classification
- national ID
- company national ID or registration ID
- tax identifier
- external-channel identifiers
- normalized phone
- email
- Telegram ID

Do not automatically merge records based only on name, phone, email, or Telegram ID.

Provide an auditable manual duplicate-review and merge flow that preserves the surviving Party ID, reassigns compatible foreign keys safely, records the merged Party IDs, and refuses ambiguous or conflicting merges.

Phase 2 — Role-specific profiles

Keep shared identity data on Party and move role-specific data into role-profile tables.

Shared Party data:

- name
- person/company classification
- phone
- email
- address
- Telegram ID
- shared notes
- identifiers

Role-specific profiles:

- customer profile: credit limit, wholesale status, customer settings
- supplier profile: payment terms and supplier-specific settings
- employee profile: reuse and extend the existing Employee table
- partner profile: ownership percentage and partner-current-account settings

Do not duplicate name, phone, email, address, or Telegram ID inside role-profile tables.

Create `party_bank_accounts` for external counterparty bank details:

- party_id
- bank name
- account holder
- account number
- card number
- IBAN
- is_default
- is_active
- notes
- created_by
- timestamps

A Party bank account is external counterparty information. It must never be treated as an internal company cash or bank ledger account.

Phase 3 — Unified Party profile UI

Create one unified Party profile and preserve existing customer and supplier URLs through redirects or role-filtered aliases.

The profile must include:

- shared identity header
- multiple active-role badges
- role activation and deactivation controls
- separate tabs for every active role
- customer details
- supplier details
- employee details
- partner details
- loans
- cheques
- payments and receipts
- documents
- complete Party transaction statement
- separate balance cards by accounting context
- one clearly labelled display-only consolidated position
- search, filters, pagination, and direct links from transactions to the Party profile

Customer, supplier, wholesale-customer, employee, and partner lists must remain separate role-filtered views over the same Party identities.

Create or consolidate a central `PartyLedgerService`. Do not calculate consolidated balances inside Blade views.

The service must provide:

- customer receivable balance
- customer credit balance
- supplier payable balance
- supplier advance balance
- employee advance balance
- payroll payable balance
- loan receivable balance
- loan payable balance
- partner current-account balance
- complete Party statement
- display-only consolidated position

Use account types or centrally resolved chart-of-account references. Do not spread new magic account-code strings across services.

The consolidated position is informational only. It must never automatically offset, settle, or modify underlying balances.

Phase 4 — Unified financial operations

Add one main action under Account Management for starting a financial operation.

Supported operation types:

- internal account transfer
- payment to a Party
- receipt from a Party
- direct deposit
- direct withdrawal
- expense
- income
- explicit balance offset
- partner operation
- loan operation
- cheque operation

The UI must be dynamic and show only fields relevant to the selected operation. Reuse existing project UI components.

Before submission, show a plain-language Persian summary describing exactly what will happen.

Internal account transfer

Create a minimal `account_transfers` domain model following the existing domain-row + journal-entry pattern.

Required fields:

- UUID
- source internal account
- destination internal account
- amount
- accounting date
- payment method
- reference number
- notes
- optional attachment
- optional bank fee
- journal_entry_id
- created_by
- approved_by when applicable
- status
- timestamps

Rules:

- Both accounts must belong to the business.
- Source and destination cannot be identical.
- Inactive accounts cannot be selected.
- The source account decreases and the destination account increases.
- The transfer must not create income or expense.
- A bank fee must post separately to the correct expense account.
- Both sides must share the same journal entry, source model, correlation reference, and transaction details.
- The operation must be visible from both account ledgers.
- Reversal must reverse the entire transfer and its fee safely.

Direct deposit and withdrawal

Create a minimal domain model for direct internal-account operations when no existing model correctly represents the business event.

A direct deposit or withdrawal must always require:

- internal bank/cash account
- amount
- accounting date
- business purpose
- valid counter-account or accounting category
- optional Party
- method
- reference
- notes
- optional attachment
- creator

Never allow a balance-only adjustment without a counter-account and journal entry.

Payment and receipt

Payments to or receipts from external people or companies are not internal transfers.

Require:

- Party
- Party bank account when relevant
- internal source or destination account
- business purpose
- related invoice, debt, credit, expense, loan, payroll item, partner account, or other valid accounting context
- amount
- accounting date
- method
- reference
- notes
- attachment

Reuse and generalize the current `party_payments` infrastructure instead of replacing it.

The workflow must distinguish:

- supplier invoice settlement
- supplier advance
- customer receipt
- customer refund
- employee advance
- payroll payment
- partner contribution
- partner withdrawal
- partner loan
- loan installment
- expense payment
- income receipt
- other classified payment or receipt

Phase 5 — Explicit balance offset

Create an auditable `party_offsets` operation for deliberate offsetting of eligible balances belonging to the same Party.

Rules:

- Never offset automatically.
- Both balances must belong to the same Party.
- Only predefined valid account combinations may be used.
- Do not allow arbitrary account-pair selection.
- The amount cannot exceed the eligible balance.
- Date, reason, reference, and creator are required.
- Post one balanced journal entry through JournalPoster.
- Both journal lines must carry the same party_id.
- Use an idempotency key.
- Reversal must use JournalPoster::reverse().
- Preserve the original offset and reversal history.

Initial supported combinations:

- customer receivable against supplier payable
- customer credit against customer receivable
- supplier advance against supplier payable

Phase 6 — Partner current accounts

Implement partner accounting as separate business operations, not as generic income or expense.

Support:

- capital contribution
- capital reduction
- partner withdrawal
- partner reimbursement
- profit distribution
- payable profit
- loan from partner
- loan to partner
- settlement of partner current account

Keep capital, withdrawal, profit distribution, and partner loans separate in the ledger and UI.

Every partner operation must reference the shared Party identity and remain visible in the complete Party statement.

Phase 7 — Loans, borrowings, installments, and cheques

Reuse the existing LoanService, Loan, LoanInstallment, ChequeService, and Cheque models.

Do not rebuild them.

Inspect and extend them only where current requirements are missing.

Loan contracts must support:

- receivable or payable direction
- Party
- principal
- start date
- maturity date
- interest calculation method
- interest amount or rate
- installment schedule
- principal portion
- interest portion
- fee portion
- penalty portion
- paid amount
- remaining principal
- due, paid, overdue, cancelled, and reversed statuses
- notes
- attachments
- creator
- journal references

Receiving a loan:

- increases an internal cash/bank account
- increases loan payable

Giving a loan:

- decreases an internal cash/bank account
- increases loan receivable

Loan installment payment:

- reduces loan payable principal
- records interest expense
- records fees or penalties separately
- reduces the internal account

Loan installment receipt:

- reduces loan receivable principal
- records interest income
- records fees or penalties separately
- increases the internal account

Wire existing cheque functionality into controllers, routes, menus, account pages, Party profiles, and responsive RTL views.

Phase 8 — Workflow, controls, and audit

Use statuses for newly introduced high-risk operations:

- draft
- pending approval
- posted
- reversed
- cancelled before posting

A draft has no journal entry.

Posting happens only once.

Use the existing Settings table for configurable controls:

- approval threshold
- negative-balance warning or blocking mode
- allowed roles for creation
- allowed roles for approval
- allowed roles for reversal

At minimum:

- accountant can create and submit
- admin can approve high-value operations
- warehouse and partner-viewer roles cannot post financial operations
- a user cannot approve their own operation when separate approval is required

Every action must record:

- creator
- submitter
- approver
- reversal actor
- timestamps
- reason
- changed metadata
- related journal entry
- activity history

Do not permit modification of accounting amount, accounts, Party, or accounting date after posting. Correct those fields through reversal and recreation.

Phase 9 — Navigation and UI integration

Repair or reuse the existing dangling navigation entries instead of adding duplicate menu structures.

Use the project’s existing components and responsive RTL design.

Required Persian UI terminology

- Party: «طرف حساب»
- Party Profile: «پرونده طرف حساب»
- Party Roles: «نقش‌های طرف حساب»
- Customer: «مشتری»
- Supplier: «تأمین‌کننده»
- Employee: «کارمند»
- Partner: «شریک»
- Other Counterparty: «سایر طرف حساب‌ها»
- Account Management: «مدیریت حساب‌ها»
- New Financial Operation: «عملیات مالی جدید»
- Internal Account: «حساب داخلی»
- Counterparty Bank Account: «حساب بانکی طرف حساب»
- Account Transfer: «انتقال بین حساب‌ها»
- Direct Deposit: «واریز مستقیم»
- Direct Withdrawal: «برداشت مستقیم»
- Payment: «پرداخت»
- Receipt: «دریافت»
- Expense: «هزینه»
- Income: «درآمد»
- Mutual Accounts: «حساب‌های دوطرفه»
- Explicit Offset: «تهاتر حساب‌ها»
- Loan: «وام و قرض»
- Loan Receivable: «وام پرداختی»
- Loan Payable: «وام دریافتی»
- Installment: «قسط»
- Principal: «اصل وام»
- Interest: «سود»
- Late Penalty: «جریمه دیرکرد»
- Bank Fee: «کارمزد بانکی»
- Cheques: «چک‌ها»
- Partner Current Account: «حساب جاری شریک»
- Partner Contribution: «آورده شریک»
- Partner Withdrawal: «برداشت شریک»
- Profit Distribution: «توزیع سود»
- Source Account: «حساب مبدأ»
- Destination Account: «حساب مقصد»
- Counter Account: «حساب مقابل»
- Payment Method: «روش پرداخت»
- Transaction Reference: «شماره پیگیری»
- Accounting Date: «تاریخ سند»
- Creator: «ثبت‌کننده»
- Approver: «تأییدکننده»
- Draft: «پیش‌نویس»
- Pending Approval: «در انتظار تأیید»
- Posted: «ثبت قطعی»
- Reversed: «برگشت‌خورده»
- Cancelled: «لغوشده»
- Active: «فعال»
- Inactive: «غیرفعال»
- Complete Statement: «گردش کامل حساب»
- Consolidated Position: «وضعیت خالص نمایشی»
- Audit History: «تاریخچه تغییرات»
- Attachments: «پیوست‌ها»
- Role Management: «مدیریت نقش‌ها»
- Person: «شخص حقیقی»
- Company: «شخص حقوقی»
- National ID: «کد ملی»
- Company National ID: «شناسه ملی»
- Tax ID: «شناسه مالیاتی»
- Duplicate Review: «بررسی موارد تکراری»
- Merge Parties: «ادغام طرف حساب‌ها»

The consolidated-position card must display this exact warning:

«این مبلغ فقط یک نمای کلی است و به معنی تهاتر یا تسویه خودکار حساب‌ها نیست.»

The internal-transfer confirmation must use this structure:

«مبلغ [amount] تومان از [source account] کسر و به [destination account] اضافه خواهد شد.»

The Party-payment confirmation must use this structure:

«مبلغ [amount] تومان از [internal account] بابت [business purpose] به [Party name] پرداخت خواهد شد.»

Execution and testing strategy

Keep execution lightweight and token-efficient.

- Do not repeatedly run the entire test suite.
- After each phase, run only directly affected Pest tests.
- Use focused file-level or filter-level test commands.
- Use grep, route:list filtering, schema inspection, static review, and migration inspection before running tests.
- Do not paste full test output into the response.
- Report only command, number of tests, pass/fail status, and relevant failures.
- Run Pint only on changed files during development.
- Run the complete test suite once, immediately before final deployment.
- Run full Pint once before deployment.
- Do not create large browser-test suites.
- Add focused HTTP-level tests for critical routes, permissions, posting, reversal, migration safety, and duplicate prevention.
- Use production-like data shapes in migration tests, but do not duplicate the full production dataset.
- Do not create or mutate real production financial records for verification.
- Use database transactions with rollback for any production-side read/render verification.
- Stop immediately on migration failure, balance mismatch, duplicate posting, broken WooCommerce customer resolution, or ledger inconsistency.

Required commit boundaries

1. Multi-role Party schema and backfill
2. Removal of single-role code assumptions
3. Unified Party profiles and role management
4. Internal transfers and direct account operations
5. Payments, receipts, offsets, and partner operations
6. Loans, installments, and cheques UI
7. Legacy cleanup and final verification

Do not combine all changes into one commit.

Deployment

- Backup the production database before migrations.
- Deploy additive migrations before code that requires them.
- Clear and rebuild route/config/view caches after deployment.
- Verify route ordering, enforced morph-map registration, permissions, and production logs.
- Verify the full request pipeline using an authorized account when credentials are available.
- Do not claim browser verification when only controllers or views were rendered in-process.
- Report the deployed commit hashes, migrations, targeted-test totals, final full-suite total, and any remaining limitations.