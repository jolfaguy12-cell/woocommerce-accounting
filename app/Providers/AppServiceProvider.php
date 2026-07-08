<?php

namespace App\Providers;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelCost;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Expenses\Models\Attachment;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Orders\Models\Order;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Receivables\Models\Cheque;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Models\PayrollRun;
use App\Domain\Reports\Models\PartnerReport;
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
            'order' => Order::class,
            'channel' => Channel::class,
            'channel_source' => ChannelSource::class,
            'channel_cost' => ChannelCost::class,
            'credit_order' => CreditOrder::class,
            'party_payment' => PartyPayment::class,
            'payroll_run' => PayrollRun::class,
            'loan' => Loan::class,
            'cheque' => Cheque::class,
            'partner_report' => PartnerReport::class,
        ]);

        // Attachments hold sensitive financial documents; partner viewers never see them.
        Gate::define('upload-attachments', fn (User $user) => $user->hasAnyRole(['admin', 'accountant', 'warehouse']));
        Gate::define('view-attachment', fn (User $user, Attachment $attachment) => $user->hasAnyRole(['admin', 'accountant'])
            || $attachment->uploaded_by === $user->id);
    }
}
