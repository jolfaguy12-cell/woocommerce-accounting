<?php

use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\AlertNotificationController;
use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\BankDepositController;
use App\Http\Controllers\Admin\ChequeController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FastFormController;
use App\Http\Controllers\Admin\FinancialOperationController;
use App\Http\Controllers\Admin\LoanController;
use App\Http\Controllers\Admin\NoteController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PackagingCostController;
use App\Http\Controllers\Admin\PartnerOperationController;
use App\Http\Controllers\Admin\PartyController;
use App\Http\Controllers\Admin\PartyOffsetController;
use App\Http\Controllers\Admin\PartyPaymentController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseInvoiceController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShowcaseController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\ToolsController;
use App\Http\Controllers\Admin\UnreceivedGoodsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Webhooks\HubWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(auth()->check() ? route('dashboard') : route('login'));
})->name('home');

Route::post('webhooks/hub', HubWebhookController::class)
    ->name('webhooks.hub');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', [SearchController::class, 'index'])->name('search.index');

    // TailAdmin demo pages, kept while the new UI is being customized.
    foreach ([
        'calendar' => ['pages.calender', 'تقویم'],
        'profile' => ['pages.profile', 'پروفایل'],
        'form-elements' => ['pages.form.form-elements', 'فرم‌ها'],
        'basic-tables' => ['pages.tables.basic-tables', 'جدول‌ها'],
        'blank' => ['pages.blank', 'صفحه خالی'],
        'error-404' => ['pages.errors.error-404', 'خطای ۴۰۴'],
        'line-chart' => ['pages.chart.line-chart', 'نمودار خطی'],
        'bar-chart' => ['pages.chart.bar-chart', 'نمودار میله‌ای'],
        'alerts' => ['pages.ui-elements.alerts', 'هشدارها'],
        'avatars' => ['pages.ui-elements.avatars', 'آواتارها'],
        'badge' => ['pages.ui-elements.badges', 'نشان‌ها'],
        'buttons' => ['pages.ui-elements.buttons', 'دکمه‌ها'],
        'image' => ['pages.ui-elements.images', 'تصاویر'],
        'videos' => ['pages.ui-elements.videos', 'ویدئوها'],
    ] as $uri => [$view, $title]) {
        Route::get($uri, fn () => view($view, ['title' => $title]))->name("tailadmin.$uri");
    }

    Route::post('attachments', [AttachmentController::class, 'store'])
        ->name('attachments.store');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    // These two literal GET routes MUST be registered before the
    // admin|accountant|warehouse group's `new-buy-order/{invoice}` below —
    // Laravel/Symfony route matching picks whichever registered route
    // matches first, and `{invoice}` would otherwise swallow the literal
    // "create"/"items" segments as a (nonexistent) invoice id, 404ing instead
    // of ever reaching this admin|accountant-only pair.
    Route::middleware('role:admin|accountant')->group(function () {
        Route::get('new-buy-order/create', [PurchaseInvoiceController::class, 'create'])->name('purchases.create');
        Route::get('new-buy-order/items/search', [PurchaseInvoiceController::class, 'searchItems'])->name('purchases.items.search');
    });

    // Read views for staff; partner viewers keep dashboard + reports only.
    Route::middleware('role:admin|accountant|warehouse')->group(function () {
        Route::get('review', [ReviewController::class, 'index'])->name('review.index');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

        // Notes are collaborative, not a financial mutation — open to all staff who can see orders.
        Route::get('notifications/notes', [NoteController::class, 'index'])->name('notifications.notes');
        Route::post('orders/{order}/notes', [NoteController::class, 'store'])->name('orders.notes.store');
        Route::delete('notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');
        Route::post('orders/{order}/labels', [OrderController::class, 'syncLabels'])->name('orders.labels');

        // In-app deliveries of the role-based alert framework (AlertDispatcher) — same audience as the notes bell above.
        Route::get('notifications/alerts', [AlertNotificationController::class, 'index'])->name('notifications.alerts');
        Route::get('notifications/alerts/{delivery}/open', [AlertNotificationController::class, 'open'])->name('notifications.alerts.open');

        // Purchase invoice reads + recording a physical receipt are open to
        // warehouse (see PurchaseInvoiceService::recordReceipt()) — everything
        // else in Purchasing/Suppliers stays admin|accountant below.
        Route::get('new-buy-order', [PurchaseInvoiceController::class, 'index'])->name('purchases.index');
        Route::get('new-buy-order/{invoice}', [PurchaseInvoiceController::class, 'show'])->name('purchases.show');
        Route::post('new-buy-order/{invoice}/receipts', [PurchaseInvoiceController::class, 'storeReceipt'])->name('purchases.receipts.store');
        Route::post('new-buy-order/{invoice}/lines/{line}/toggle-received', [PurchaseInvoiceController::class, 'toggleReceipt'])->name('purchases.lines.toggle');
        Route::post('new-buy-order/{invoice}/receipt-lines/{receiptLine}', [PurchaseInvoiceController::class, 'updateReceiptLine'])->name('purchases.receipt-lines.update');

        // Overdue receiving — same admin|accountant|warehouse audience as the rest of purchasing reads above.
        Route::get('unreceived-goods', [UnreceivedGoodsController::class, 'index'])->name('unreceived-goods.index');
    });

    // Financial mutations are for admin/accountant only.
    Route::middleware('role:admin|accountant')->group(function () {
        Route::post('review/{item}/resolve', [ReviewController::class, 'resolve'])->name('review.resolve');
        Route::post('review/sources/{source}/map', [ReviewController::class, 'mapSource'])->name('review.map-source');
        Route::post('orders/{order}/shipping', [OrderController::class, 'setShipping'])->name('orders.shipping');
        Route::post('orders/{order}/packaging', [OrderController::class, 'setPackaging'])->name('orders.packaging');
        Route::post('orders/{order}/packaging/reset', [OrderController::class, 'resetPackaging'])->name('orders.packaging.reset');
        Route::post('orders/{order}/recalc', [OrderController::class, 'recalc'])->name('orders.recalc');
        Route::post('orders/{order}/payment-method', [OrderController::class, 'setPaymentMethod'])->name('orders.payment-method');
        Route::post('products/{product}/map', [ProductController::class, 'map'])->name('products.map');
        Route::post('products/{product}/wholesale', [ProductController::class, 'setWholesale'])->name('products.wholesale');
        Route::post('products/{product}/cost', [ProductController::class, 'storeCost'])->name('products.cost');
        Route::post('products/{product}/quick-cost', [ProductController::class, 'storeQuickCost'])->name('products.quick-cost');
        Route::post('products/{product}/notes', [ProductController::class, 'storeNote'])->name('products.notes');
        Route::post('products/{product}/sync', [ProductController::class, 'syncFromHub'])->name('products.sync');

        // The unified Party profile: one identity, many roles. The customer and
        // supplier pages below stay as role-filtered views over these same
        // parties — this adds the identity, roles, cross-role balances and the
        // complete statement, which no single-role page could show.
        // `duplicates` and `search` are declared BEFORE `{party}` or the wildcard
        // swallows them.
        Route::get('parties', [PartyController::class, 'index'])->name('parties.index');
        Route::get('parties/duplicates', [PartyController::class, 'duplicates'])->name('parties.duplicates');
        // The one party picker's backend (<x-form.party-select>) — server-side
        // search over every party, so no form has to cap its dropdown again.
        Route::get('parties/search', [PartyController::class, 'search'])->name('parties.search');
        Route::get('parties/{party}', [PartyController::class, 'show'])->name('parties.show');
        Route::post('parties/{party}/roles/activate', [PartyController::class, 'activateRole'])->name('parties.roles.activate');
        Route::post('parties/{party}/roles/deactivate', [PartyController::class, 'deactivateRole'])->name('parties.roles.deactivate');
        Route::post('parties/{party}/bank-accounts', [PartyController::class, 'storeBankAccount'])->name('parties.bank-accounts.store');
        Route::delete('parties/{party}/bank-accounts/{bankAccount}', [PartyController::class, 'destroyBankAccount'])->name('parties.bank-accounts.destroy');

        // «حساب کارمند» — payroll and employee balances are sensitive financial data
        // (CLAUDE.md), so they live in the admin|accountant group and nowhere wider.
        // The employee page is keyed by PARTY, not by employee id: an employee is a
        // party with the employee role, and their salary, their advance, the expenses
        // they covered and anything they bought from us are all balances on that one
        // identity.
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/{party}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::put('employees/{party}', [EmployeeController::class, 'updateProfile'])->name('employees.update');
        Route::post('employees/{party}/salary-payment', [EmployeeController::class, 'paySalary'])->name('employees.salary-payment');
        Route::post('employees/{party}/advance', [EmployeeController::class, 'payAdvance'])->name('employees.advance');

        // «ثبت حقوق دوره» — accrual. Payment is a separate event on the employee page.
        // `create` before `{run}` or the wildcard swallows the literal.
        Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::get('payroll/create', [PayrollController::class, 'create'])->name('payroll.create');
        Route::post('payroll', [PayrollController::class, 'store'])->name('payroll.store');
        Route::get('payroll/{run}', [PayrollController::class, 'show'])->name('payroll.show');
        Route::post('payroll/{run}/reverse', [PayrollController::class, 'reverse'])->name('payroll.reverse');

        // «هزینه‌ها» — the list, plus the two operations that close an expense the
        // company had not actually paid. `reimbursements` before `{expense}`.
        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('expenses/reimbursements/create', [ExpenseController::class, 'createReimbursement'])->name('expenses.reimbursements.create');
        Route::post('expenses/reimbursements', [ExpenseController::class, 'storeReimbursement'])->name('expenses.reimbursements.store');
        Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        Route::post('expenses/{expense}/settle', [ExpenseController::class, 'settle'])->name('expenses.settle');

        // Customer management surfaces per-customer profit/purchase volume — sensitive, admin/accountant only.
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{party}', [CustomerController::class, 'show'])->name('customers.show');
        Route::post('customers/{party}/wholesale', [CustomerController::class, 'setWholesale'])->name('customers.wholesale');
        Route::post('customers/{party}/phone', [CustomerController::class, 'setPhone'])->name('customers.phone');
        Route::post('customers/{party}/telegram', [CustomerController::class, 'setTelegramId'])->name('customers.telegram');
        Route::post('customers/{party}/settlement', [CustomerController::class, 'recordSettlement'])->name('customers.settlement');
        Route::post('customers/{party}/credit-sale', [CustomerController::class, 'storeCreditSale'])->name('customers.credit-sale');
        Route::post('customers/{party}/write-off', [CustomerController::class, 'storeWriteOff'])->name('customers.write-off');
        Route::get('wholesale-customers', [CustomerController::class, 'wholesaleIndex'])->name('wholesale-customers.index');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::post('suppliers/{supplier}/pay', [SupplierController::class, 'pay'])->name('suppliers.pay');
        Route::get('suppliers/{supplier}/purchase-history', [SupplierController::class, 'purchaseHistory'])->name('suppliers.purchase-history');
        Route::get('suppliers/{supplier}/transactions', [SupplierController::class, 'transactions'])->name('suppliers.transactions');
        Route::get('suppliers/{supplier}/overdue', [SupplierController::class, 'overdue'])->name('suppliers.overdue');
        Route::post('suppliers/{supplier}/refund', [SupplierController::class, 'refund'])->name('suppliers.refund');
        Route::post('suppliers/{supplier}/credit', [SupplierController::class, 'storeCredit'])->name('suppliers.credit');

        Route::post('new-buy-order', [PurchaseInvoiceController::class, 'store'])->name('purchases.store');
        Route::get('new-buy-order/{invoice}/edit', [PurchaseInvoiceController::class, 'edit'])->name('purchases.edit');
        Route::put('new-buy-order/{invoice}', [PurchaseInvoiceController::class, 'update'])->name('purchases.update');
        Route::post('new-buy-order/{invoice}/finalize', [PurchaseInvoiceController::class, 'finalize'])->name('purchases.finalize');
        Route::post('new-buy-order/{invoice}/images', [PurchaseInvoiceController::class, 'storeImages'])->name('purchases.images.store');
        Route::delete('new-buy-order/{invoice}/images/{attachment}', [PurchaseInvoiceController::class, 'destroyImage'])->name('purchases.images.destroy');
        Route::post('new-buy-order/{invoice}/returns', [PurchaseInvoiceController::class, 'storeReturn'])->name('purchases.returns.store');

        Route::put('party-payments/{payment}/note', [PartyPaymentController::class, 'updateNote'])->name('party-payments.notes.update');

        Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
        Route::get('new-bank-account', [BankAccountController::class, 'index'])->name('bank-accounts.create');
        Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::get('bank-accounts/deposits', [BankDepositController::class, 'index'])->name('deposits.index');
        Route::post('bank-accounts/deposits/import', [BankDepositController::class, 'import'])->name('deposits.import');
        Route::get('bank-accounts/{bankAccount}', [BankAccountController::class, 'show'])->name('bank-accounts.show');

        // «عملیات مالی جدید» — the one entry point for money movements that had no
        // home: transfers between our own accounts, and direct deposits/withdrawals
        // against an explicit counter-account. Approval and reversal are controlled
        // by the `ops.*` settings (App\Domain\Accounting\Support\OperationPolicy),
        // not by the route, so tightening them is an admin action, not a deploy.
        // `create` is declared before the {model} routes so the literal wins.
        Route::get('financial-operations', [FinancialOperationController::class, 'index'])->name('financial-operations.index');
        Route::get('financial-operations/create', [FinancialOperationController::class, 'create'])->name('financial-operations.create');
        Route::post('financial-operations', [FinancialOperationController::class, 'store'])->name('financial-operations.store');

        Route::get('financial-operations/transfers/{transfer}', [FinancialOperationController::class, 'showTransfer'])->name('financial-operations.transfers.show');
        Route::post('financial-operations/transfers/{transfer}/approve', [FinancialOperationController::class, 'approveTransfer'])->name('financial-operations.transfers.approve');
        Route::post('financial-operations/transfers/{transfer}/reverse', [FinancialOperationController::class, 'reverseTransfer'])->name('financial-operations.transfers.reverse');
        Route::post('financial-operations/transfers/{transfer}/cancel', [FinancialOperationController::class, 'cancelTransfer'])->name('financial-operations.transfers.cancel');

        Route::get('financial-operations/transactions/{transaction}', [FinancialOperationController::class, 'showTransaction'])->name('financial-operations.transactions.show');
        Route::post('financial-operations/transactions/{transaction}/approve', [FinancialOperationController::class, 'approveTransaction'])->name('financial-operations.transactions.approve');
        Route::post('financial-operations/transactions/{transaction}/reverse', [FinancialOperationController::class, 'reverseTransaction'])->name('financial-operations.transactions.reverse');
        Route::post('financial-operations/transactions/{transaction}/cancel', [FinancialOperationController::class, 'cancelTransaction'])->name('financial-operations.transactions.cancel');

        // «حساب‌های دوطرفه» — netting two balances the SAME party holds. These are
        // the URLs the sidebar has pointed at since long before the routes existed.
        // `create` before `{offset}` or the wildcard swallows the literal.
        Route::get('mutual-accounts', [PartyOffsetController::class, 'index'])->name('mutual-accounts.index');
        Route::get('mutual-accounts/create', [PartyOffsetController::class, 'create'])->name('mutual-accounts.create');
        Route::post('mutual-accounts', [PartyOffsetController::class, 'store'])->name('mutual-accounts.store');
        Route::get('mutual-accounts/{offset}', [PartyOffsetController::class, 'show'])->name('mutual-accounts.show');
        Route::post('mutual-accounts/{offset}/approve', [PartyOffsetController::class, 'approve'])->name('mutual-accounts.approve');
        Route::post('mutual-accounts/{offset}/reverse', [PartyOffsetController::class, 'reverse'])->name('mutual-accounts.reverse');
        Route::post('mutual-accounts/{offset}/cancel', [PartyOffsetController::class, 'cancel'])->name('mutual-accounts.cancel');

        // «عملیات شرکا» — capital, drawings, profit shares and partner loans, each
        // on its own accounts (never generic income/expense).
        Route::get('partner-operations', [PartnerOperationController::class, 'index'])->name('partner-operations.index');
        Route::get('partner-operations/create', [PartnerOperationController::class, 'create'])->name('partner-operations.create');
        Route::post('partner-operations', [PartnerOperationController::class, 'store'])->name('partner-operations.store');
        Route::get('partner-operations/{partnerOperation}', [PartnerOperationController::class, 'show'])->name('partner-operations.show');
        Route::post('partner-operations/{partnerOperation}/approve', [PartnerOperationController::class, 'approve'])->name('partner-operations.approve');
        Route::post('partner-operations/{partnerOperation}/reverse', [PartnerOperationController::class, 'reverse'])->name('partner-operations.reverse');
        Route::post('partner-operations/{partnerOperation}/cancel', [PartnerOperationController::class, 'cancel'])->name('partner-operations.cancel');

        // «وام و اقساط» — loans in both directions. «وام دریافتی» is money we borrowed
        // (a liability, 2200); «وام پرداختی» is money we lent (an asset, 1600). The
        // outstanding principal is never stored — it is read back out of the ledger.
        // `create` before `{loan}` or the wildcard swallows the literal.
        Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
        Route::get('loans/create', [LoanController::class, 'create'])->name('loans.create');
        Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
        Route::get('loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
        Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])->name('loans.approve');
        Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])->name('loans.cancel');
        Route::post('loans/{loan}/reverse', [LoanController::class, 'reverse'])->name('loans.reverse');
        Route::post('loans/{loan}/installments', [LoanController::class, 'payInstallment'])->name('loans.installments.pay');
        Route::post('loans/{loan}/installments/{installment}/reverse', [LoanController::class, 'reverseInstallment'])
            ->name('loans.installments.reverse');

        // «چک‌ها» — a cheque is a promise, and 1250/2100 are where a promise lives until
        // it is kept. Only clearing touches a real bank account.
        Route::get('cheques', [ChequeController::class, 'index'])->name('cheques.index');
        Route::get('cheques/create', [ChequeController::class, 'create'])->name('cheques.create');
        Route::post('cheques', [ChequeController::class, 'store'])->name('cheques.store');
        Route::get('cheques/{cheque}', [ChequeController::class, 'show'])->name('cheques.show');
        Route::post('cheques/{cheque}/clear', [ChequeController::class, 'clear'])->name('cheques.clear');
        Route::post('cheques/{cheque}/bounce', [ChequeController::class, 'bounce'])->name('cheques.bounce');
        Route::post('cheques/{cheque}/cancel', [ChequeController::class, 'cancel'])->name('cheques.cancel');
        Route::post('cheques/{cheque}/reverse', [ChequeController::class, 'reverse'])->name('cheques.reverse');

        Route::get('fast-forms', [FastFormController::class, 'index'])->name('fast-forms');
        Route::post('fast-forms/expense', [FastFormController::class, 'storeExpense'])->name('fast-forms.expense');
        Route::post('fast-forms/topup', [FastFormController::class, 'storeTopup'])->name('fast-forms.topup');
        Route::post('fast-forms/payment', [FastFormController::class, 'storePayment'])->name('fast-forms.payment');
        Route::post('fast-forms/bank', [FastFormController::class, 'storeBank'])->name('fast-forms.bank');
    });

    // Reports: staff + partner viewers can read; only admin finalizes.
    Route::middleware('role:admin|accountant|partner_viewer')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{period}', [ReportController::class, 'show'])->name('reports.show');
    });
    Route::post('reports/{period}/finalize', [ReportController::class, 'finalize'])
        ->middleware('role:admin')->name('reports.finalize');

    // User management: only admins may create/update accounts (public registration is disabled).
    // Internal component showcase: admin-only dev/admin reference tool.
    Route::middleware('role:admin')->group(function () {
        Route::get('components', [ShowcaseController::class, 'overview'])->name('components.overview');
        Route::get('components/{category}', [ShowcaseController::class, 'category'])->name('components.category');
    });

    Route::middleware('role:admin')->group(function () {
        // «ادغام طرف حساب‌ها» — irreversible-looking and identity-level, so admin
        // only. It rewrites no journal line (see PartyMergeService), but it does
        // decide that two histories belong to one person.
        Route::post('parties/{party}/merge', [PartyController::class, 'merge'])->name('parties.merge');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Tools & Settings: system administration, admin-only. Pages review which
    // backend capabilities already exist and which still need building.
    Route::middleware('role:admin')->group(function () {
        Route::get('tools/backup', [ToolsController::class, 'backup'])->name('tools.backup');
        Route::get('tools/system-status', [ToolsController::class, 'systemStatus'])->name('tools.system-status');
        Route::get('tools/system-logs', [ToolsController::class, 'systemLogs'])->name('tools.system-logs');
        Route::post('tools/system-logs/retry', [ToolsController::class, 'retryWebhookEvents'])->name('tools.system-logs.retry');

        Route::get('tools/alerts', [AlertController::class, 'index'])->name('tools.alerts');
        Route::post('tools/alerts/{alertType}/toggle', [AlertController::class, 'toggleActive'])->name('tools.alerts.toggle');
        Route::post('tools/alerts/{alertType}/roles', [AlertController::class, 'updateRoles'])->name('tools.alerts.roles');
        Route::post('tools/alerts/{alertType}/template', [AlertController::class, 'updateTemplate'])->name('tools.alerts.template');

        Route::get('setting', [SettingController::class, 'general'])->name('setting.general');
        Route::get('setting/report-settings', [SettingController::class, 'reportSettings'])->name('setting.report-settings');
        Route::get('setting/role-managment', [SettingController::class, 'roleManagement'])->name('setting.role-management');
        Route::get('setting/api-webhook-managment', [SettingController::class, 'apiWebhookManagement'])->name('setting.api-webhook-management');
        Route::post('setting/api-webhook-managment/telegram', [SettingController::class, 'updateTelegram'])->name('setting.api-webhook-management.telegram.update');
        Route::post('setting/api-webhook-managment/telegram/reset', [SettingController::class, 'resetTelegram'])->name('setting.api-webhook-management.telegram.reset');

        Route::get('warehouse/packaging-cost', [PackagingCostController::class, 'index'])->name('warehouse.packaging-cost');
        Route::post('warehouse/packaging-cost/defaults', [PackagingCostController::class, 'updateDefaults'])->name('warehouse.packaging-cost.defaults');
        Route::post('warehouse/packaging-cost/tiers', [PackagingCostController::class, 'storeTier'])->name('warehouse.packaging-cost.tiers.store');
        Route::put('warehouse/packaging-cost/tiers/{tier}', [PackagingCostController::class, 'updateTier'])->name('warehouse.packaging-cost.tiers.update');
        Route::delete('warehouse/packaging-cost/tiers/{tier}', [PackagingCostController::class, 'destroyTier'])->name('warehouse.packaging-cost.tiers.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
