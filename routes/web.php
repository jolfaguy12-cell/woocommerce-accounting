<?php

use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\BankDepositController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FastFormController;
use App\Http\Controllers\Admin\NoteController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PackagingCostController;
use App\Http\Controllers\Admin\PartyPaymentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseInvoiceController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShowcaseController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\ToolsController;
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

        // Purchase invoice reads + recording a physical receipt are open to
        // warehouse (see PurchaseInvoiceService::recordReceipt()) — everything
        // else in Purchasing/Suppliers stays admin|accountant below.
        Route::get('new-buy-order', [PurchaseInvoiceController::class, 'index'])->name('purchases.index');
        Route::get('new-buy-order/{invoice}', [PurchaseInvoiceController::class, 'show'])->name('purchases.show');
        Route::post('new-buy-order/{invoice}/receipts', [PurchaseInvoiceController::class, 'storeReceipt'])->name('purchases.receipts.store');
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

        // Customer management surfaces per-customer profit/purchase volume — sensitive, admin/accountant only.
        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{party}', [CustomerController::class, 'show'])->name('customers.show');
        Route::post('customers/{party}/wholesale', [CustomerController::class, 'setWholesale'])->name('customers.wholesale');
        Route::post('customers/{party}/phone', [CustomerController::class, 'setPhone'])->name('customers.phone');
        Route::post('customers/{party}/settlement', [CustomerController::class, 'recordSettlement'])->name('customers.settlement');
        Route::post('customers/{party}/credit-sale', [CustomerController::class, 'storeCreditSale'])->name('customers.credit-sale');
        Route::post('customers/{party}/write-off', [CustomerController::class, 'storeWriteOff'])->name('customers.write-off');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::post('suppliers/{supplier}/pay', [SupplierController::class, 'pay'])->name('suppliers.pay');
        Route::get('suppliers/{supplier}/purchase-history', [SupplierController::class, 'purchaseHistory'])->name('suppliers.purchase-history');
        Route::get('suppliers/{supplier}/transactions', [SupplierController::class, 'transactions'])->name('suppliers.transactions');
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

        Route::get('warehouse/packaging-cost', [PackagingCostController::class, 'index'])->name('warehouse.packaging-cost');
        Route::post('warehouse/packaging-cost/defaults', [PackagingCostController::class, 'updateDefaults'])->name('warehouse.packaging-cost.defaults');
        Route::post('warehouse/packaging-cost/tiers', [PackagingCostController::class, 'storeTier'])->name('warehouse.packaging-cost.tiers.store');
        Route::put('warehouse/packaging-cost/tiers/{tier}', [PackagingCostController::class, 'updateTier'])->name('warehouse.packaging-cost.tiers.update');
        Route::delete('warehouse/packaging-cost/tiers/{tier}', [PackagingCostController::class, 'destroyTier'])->name('warehouse.packaging-cost.tiers.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
