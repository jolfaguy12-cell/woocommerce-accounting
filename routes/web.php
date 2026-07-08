<?php

use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FastFormController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Webhooks\HubWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::post('webhooks/hub', HubWebhookController::class)
    ->name('webhooks.hub');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('attachments', [AttachmentController::class, 'store'])
        ->name('attachments.store');
    Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])
        ->name('attachments.download');

    // Read views for staff; partner viewers keep dashboard + reports only.
    Route::middleware('role:admin|accountant|warehouse')->group(function () {
        Route::get('review', [ReviewController::class, 'index'])->name('review.index');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
    });

    // Financial mutations are for admin/accountant only.
    Route::middleware('role:admin|accountant')->group(function () {
        Route::post('review/{item}/resolve', [ReviewController::class, 'resolve'])->name('review.resolve');
        Route::post('review/sources/{source}/map', [ReviewController::class, 'mapSource'])->name('review.map-source');
        Route::post('orders/{order}/shipping', [OrderController::class, 'setShipping'])->name('orders.shipping');
        Route::post('orders/{order}/recalc', [OrderController::class, 'recalc'])->name('orders.recalc');
        Route::post('products/{product}/map', [ProductController::class, 'map'])->name('products.map');
        Route::post('products/{product}/wholesale', [ProductController::class, 'setWholesale'])->name('products.wholesale');

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
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
