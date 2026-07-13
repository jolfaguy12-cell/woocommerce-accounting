<?php

namespace App\Providers;

use App\Domain\Accounting\Models\CustomerProfile;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\PartnerProfile;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyBankAccount;
use App\Domain\Accounting\Models\PartyExternalId;
use App\Domain\Accounting\Models\PartyRole;
use App\Domain\Accounting\Models\SupplierProfile;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\Models\ChannelCost;
use App\Domain\Channels\Models\ChannelSource;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseInvoiceReceiptLine;
use App\Domain\Costing\Models\PurchaseReturn;
use App\Domain\Expenses\Models\Attachment;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Expenses\Models\BankDeposit;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Orders\Models\Order;
use App\Domain\Products\Models\ProductMirror;
use App\Domain\Receivables\Models\BadDebtWriteOff;
use App\Domain\Receivables\Models\Cheque;
use App\Domain\Receivables\Models\CreditOrder;
use App\Domain\Receivables\Models\CreditOrderSettlement;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Models\PayrollRun;
use App\Domain\Receivables\Models\SupplierCreditAdjustment;
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
            'purchase_invoice_receipt_line' => PurchaseInvoiceReceiptLine::class,
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
            'bank_deposit' => BankDeposit::class,
            'bank_account' => BankAccount::class,
            'bad_debt_write_off' => BadDebtWriteOff::class,
            'credit_order_settlement' => CreditOrderSettlement::class,
            'party' => Party::class,
            // Not journal sources — but the morph map is enforced, so any model
            // whose class is resolved to a morph alias (LogsActivity does exactly
            // that for its subject) must be registered here or it throws.
            'party_role' => PartyRole::class,
            'party_bank_account' => PartyBankAccount::class,
            'party_external_id' => PartyExternalId::class,
            'customer_profile' => CustomerProfile::class,
            'supplier_profile' => SupplierProfile::class,
            'partner_profile' => PartnerProfile::class,
            'purchase_return' => PurchaseReturn::class,
            'supplier_credit_adjustment' => SupplierCreditAdjustment::class,
        ]);

        // Attachments hold sensitive financial documents; partner viewers never see them.
        Gate::define('upload-attachments', fn (User $user) => $user->hasAnyRole(['admin', 'accountant', 'warehouse']));
        Gate::define('view-attachment', fn (User $user, Attachment $attachment) => $user->hasAnyRole(['admin', 'accountant'])
            || $attachment->uploaded_by === $user->id);
    }
}
