<?php

namespace App\Providers;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Expenses\Models\Attachment;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Sync\Models\RawOrder;
use App\Domain\Sync\Models\ReviewItem;
use App\Domain\Sync\Models\WebhookEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'expense' => Expense::class,
            'attachment' => Attachment::class,
            'webhook_event' => WebhookEvent::class,
            'raw_order' => RawOrder::class,
            'review_item' => ReviewItem::class,
            'journal_entry' => JournalEntry::class,
            'purchase_invoice' => PurchaseInvoice::class,
            'product_mirror' => ProductMirror::class,
        ]);

        // Attachments hold sensitive financial documents; partner viewers never see them.
        Gate::define('upload-attachments', fn (User $user) => $user->hasAnyRole(['admin', 'accountant', 'warehouse']));
        Gate::define('view-attachment', fn (User $user, Attachment $attachment) => $user->hasAnyRole(['admin', 'accountant'])
            || $attachment->uploaded_by === $user->id);
    }
}
