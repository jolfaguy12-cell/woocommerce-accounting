<?php

use App\Http\Controllers\Admin\AttachmentController;
use App\Http\Controllers\Admin\DashboardController;
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
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
