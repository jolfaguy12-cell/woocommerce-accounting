<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PaymentPurpose;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use App\Domain\Expenses\Support\ExpenseSettlementStatus;
use App\Domain\Receivables\Models\PartyPayment;
use App\Domain\Receivables\Services\PaymentRecorder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * «تسویه هزینه پرداخت‌نشده» — paying a bill the company recorded as owed.
 *
 * An expense with funding source `unpaid` credits accounts payable (2000) against
 * the creditor's own party id: the cost is recognised on the day it is incurred,
 * and the debt is real from that moment. What was missing was the other end — the
 * day we pay it. Without this, the only way to make the payable go away was to
 * record a SECOND expense against the bank, which recognises the same cost twice
 * (and balances perfectly while doing it).
 *
 * So a settlement is a payment, not an expense. It debits 2000 and credits the
 * bank; the profit and loss account is not touched, because nothing new was spent.
 *
 * ── Why the remaining balance is derived, every single time ──
 *
 * There is no `paid` column on `expenses` and there will not be one. A stored flag
 * and the ledger can disagree — a reversed payment, a hand-posted correction, a
 * failed transaction — and when they disagree, it is the flag that gets believed
 * and the ledger that is right. So `settled()` sums the AP movement on the journal
 * entries that belong to this expense's settlements, INCLUDING their reversals,
 * which is why a reversed settlement puts its money straight back on the bill
 * without anything having to remember to un-flag it.
 */
class ExpenseSettlementService
{
    public function __construct(private readonly PaymentRecorder $payments) {}

    /**
     * How much of this expense has actually been paid, according to the ledger.
     *
     * Debits to 2000 on the settlement entries, minus credits — a reversal of a
     * settlement re-credits 2000, so it nets itself out to zero here and the bill
     * is outstanding again, automatically.
     */
    public function settled(Expense $expense): int
    {
        $entryIds = $this->settlementEntryIds($expense);

        if ($entryIds === []) {
            return 0;
        }

        $sums = JournalLine::whereIn('journal_entry_id', $entryIds)
            ->where('account_id', AccountCode::AccountsPayable->account()->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return (int) $sums->debit - (int) $sums->credit;
    }

    /** What is still owed on this expense. Never negative — an overpayment cannot exist (see settle()). */
    public function remaining(Expense $expense): int
    {
        if (! $this->isSettleable($expense)) {
            return 0;
        }

        return max(0, (int) $expense->amount - $this->settled($expense));
    }

    public function status(Expense $expense): ExpenseSettlementStatus
    {
        if (! $this->isSettleable($expense)) {
            // A bank-funded expense was paid the day it was entered; it has no payable
            // and nothing to settle. Reporting it as «پرداخت‌نشده» would be a lie.
            return ExpenseSettlementStatus::Paid;
        }

        return ExpenseSettlementStatus::forRemaining((int) $expense->amount, $this->remaining($expense));
    }

    /** Only an expense the company still OWES — i.e. one booked to accounts payable. */
    public function isSettleable(Expense $expense): bool
    {
        return $expense->fundingSource() === ExpenseFundingSource::Unpaid;
    }

    /**
     * Pay (part of) an unpaid expense.
     *
     * Partial settlement is a first-class case, not an edge one: a supplier bill
     * paid in two instalments is two settlements against one expense, and the
     * expense is «بخشی پرداخت‌شده» in between.
     *
     * Overpayment and double settlement are the same guard — the cap is the
     * REMAINING balance, so once a bill is fully paid there is nothing left to pay
     * and a duplicate submit is refused rather than posting a second payment that
     * would drive the creditor's payable negative.
     */
    public function settle(Expense $expense, array $data): PartyPayment
    {
        if (! $this->isSettleable($expense)) {
            throw new InvalidArgumentException(
                'فقط هزینه‌ای که به‌صورت «پرداخت‌نشده» ثبت شده باشد تسویه می‌شود؛ این هزینه از حساب شرکت پرداخت شده است.'
            );
        }

        // The creditor. Recorded on the expense at the moment it was entered — an
        // unpaid expense that cannot name who it is owed to is a plug, and
        // ExpenseRecorder refuses to create one.
        $partyId = $expense->funded_by_party_id
            ?? throw new InvalidArgumentException('این هزینه طرف حساب بستانکار ندارد و قابل تسویه نیست.');

        $party = Party::live($partyId);

        return $this->payments->settleUnpaidExpense(
            expense: $expense,
            party: $party,
            amount: (int) $data['amount'],
            remaining: $this->remaining($expense),
            bankAccountId: (int) $data['bank_account_id'],
            accountingDate: $data['accounting_date'] ?? null,
            reference: $data['reference'] ?? null,
            note: $data['note'] ?? null,
            by: $data['created_by'] ?? null,
        );
    }

    /** Every settlement posted against this expense, newest first — the audit trail on its page. */
    public function settlements(Expense $expense): Collection
    {
        return PartyPayment::query()
            ->with(['bankAccount', 'party', 'creator', 'reverser'])
            ->where('applied_type', $expense->getMorphClass())
            ->where('applied_id', $expense->id)
            ->where('purpose', PaymentPurpose::UnpaidExpenseSettlement->value)
            ->latest('id')
            ->get();
    }

    /**
     * The journal entries that moved money on this bill: the settlement payments'
     * own entries, plus any entry that reverses one of them.
     *
     * A reversal is not linked to the payment — it is linked to the ENTRY it undoes
     * (`reversal_of_entry_id`), which is the only honest way to model it: the
     * reversal exists because of the entry, not because of the row that caused it.
     *
     * @return list<int>
     */
    private function settlementEntryIds(Expense $expense): array
    {
        $entryIds = PartyPayment::query()
            ->where('applied_type', $expense->getMorphClass())
            ->where('applied_id', $expense->id)
            ->where('purpose', PaymentPurpose::UnpaidExpenseSettlement->value)
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($entryIds === []) {
            return [];
        }

        $reversals = JournalEntry::whereIn('reversal_of_entry_id', $entryIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$entryIds, ...$reversals]));
    }
}
