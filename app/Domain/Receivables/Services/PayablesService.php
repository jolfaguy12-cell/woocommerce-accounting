<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Party;
use App\Support\Design\TableQuery;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Supplier-side mirror of ReceivablesService: both live in this domain because
 * the party-ledger logic here (like ChequeService) is already generic across
 * AR and AP, not receivables-specific despite the folder name.
 */
class PayablesService
{
    private const AP = '2000';

    /** >0: we owe the supplier (payable). <0: the supplier owes us (overpaid/advance). 0: settled. */
    public function partyPayableBalance(Party $party): int
    {
        $lines = $this->apAccount()->lines()->where('party_id', $party->id);

        return (int) $lines->sum('credit') - (int) $lines->sum('debit');
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
            ->with('entry')
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

            return $line;
        });

        return $transactions;
    }

    private function apAccount(): Account
    {
        return Account::firstWhere('code', self::AP);
    }
}
