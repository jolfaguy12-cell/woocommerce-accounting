<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Costing\Models\PurchaseReturn;
use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Models\SupplierCreditAdjustment;
use App\Support\Design\TableQuery;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Supplier-side mirror of ReceivablesService: both live in this domain because
 * the party-ledger logic here (like ChequeService) is already generic across
 * AR and AP, not receivables-specific despite the folder name.
 */
class PayablesService
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

    /** >0: we owe the supplier (payable). <0: the supplier owes us (overpaid/advance). 0: settled. */
    public function partyPayableBalance(Party $party): int
    {
        return $this->ledger->supplierPayable($party);
    }

    /**
     * This supplier's AP ledger with a running balance — same technique as
     * BankAccountController::show() (compute ascending running balance first,
     * then paginate a separately sorted/filtered query), scoped to party_id on
     * the AP account instead of one bank account.
     */
    public function ledger(Party $party, TableQuery $query): LengthAwarePaginator
    {
        $account = $this->apAccount();
        $search = $query->search() ?? '';

        $runningBalance = [];
        $balance = 0;
        $account->lines()->where('party_id', $party->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')->orderBy('journal_lines.id')
            ->get(['journal_lines.id', 'journal_lines.debit', 'journal_lines.credit'])
            ->each(function ($line) use (&$balance, &$runningBalance) {
                $balance += $line->credit - $line->debit;
                $runningBalance[$line->id] = $balance;
            });

        $transactions = $account->lines()->where('party_id', $party->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            // entry.source lets the view render a rich, typed row (payment vs
            // invoice vs return vs manual credit) and its own clickable link.
            // bankAccount is per-morph-type (only PartyPayment has one) so it
            // is loaded via morphWith rather than plain dot-notation, which
            // would error on source types with no such relation.
            ->with(['entry', 'entry.source' => function (MorphTo $morphTo) {
                $morphTo->morphWith([PartyPayment::class => ['bankAccount', 'creator', 'editor']]);
            }])
            ->when($search !== '', fn ($q) => $q->where('journal_entries.description', 'like', "%{$search}%"))
            ->select('journal_lines.*')
            ->tap(fn ($q) => $query->apply($q))
            // Stable tiebreaker: two lines can share an entry_date, and a ledger
            // must not reshuffle between page loads.
            ->orderByDesc('journal_lines.id')
            ->paginate($query->perPage())
            ->withQueryString();

        $transactions->getCollection()->transform(function ($line) use ($runningBalance) {
            $line->balance_after = $runningBalance[$line->id] ?? null;
            $line->described = $this->describeLine($line);

            return $line;
        });

        return $transactions;
    }

    /**
     * Last $limit AP lines for a supplier, described and ready to render —
     * used by the supplier overview page's "آخرین تراکنش‌های مالی" preview so
     * it shows the same rich, clickable detail as the full transactions tab
     * (bank account, method, reference, notes, creator) instead of a bare
     * description+amount line.
     */
    public function recentLines(Party $party, int $limit = 8): Collection
    {
        $lines = $this->apAccount()->lines()->where('party_id', $party->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->with(['entry', 'entry.source' => function (MorphTo $morphTo) {
                $morphTo->morphWith([PartyPayment::class => ['bankAccount', 'creator', 'editor']]);
            }])
            ->select('journal_lines.*')
            ->orderByDesc('journal_entries.entry_date')->orderByDesc('journal_lines.id')
            ->limit($limit)
            ->get();

        return $lines->map(fn ($line) => tap($line, fn ($l) => $l->described = $this->describeLine($l)));
    }

    /**
     * The single place that turns a raw AP journal line into a typed, labeled,
     * linkable row — shared by the full transactions tab and the overview
     * preview so "what kind of transaction is this, and where does it link"
     * is never duplicated (or allowed to drift) across two Blade files.
     */
    public function describeLine(JournalLine $line): array
    {
        $source = $line->entry->source;
        $isPayment = $source instanceof PartyPayment;
        $isReturn = $source instanceof PurchaseReturn;
        $isInvoice = $source instanceof PurchaseInvoice;
        $isCredit = $source instanceof SupplierCreditAdjustment;

        $type = match (true) {
            $isInvoice => ['label' => 'فاکتور خرید', 'color' => 'light', 'url' => route('purchases.show', $source)],
            $isReturn => ['label' => 'برگشت از خرید', 'color' => 'warning', 'url' => route('purchases.show', $source->purchase_invoice_id)],
            $isCredit => ['label' => 'اعتبار دستی', 'color' => 'info', 'url' => null],
            $isPayment => ['label' => $source->direction === 'out' ? 'پرداخت' : 'بازپرداخت', 'color' => $source->direction === 'out' ? 'success' : 'primary', 'url' => null],
            default => ['label' => '—', 'color' => 'light', 'url' => null],
        };

        return [
            'date' => JalaliPeriod::fmtDate($line->entry->entry_date),
            'description' => $line->entry->description,
            'type' => $type,
            'payment' => $isPayment ? $source : null,
        ];
    }

    private function apAccount(): Account
    {
        return AccountCode::AccountsPayable->account();
    }
}
