<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Support\Design\TableQuery;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Every balance a Party can have, in one place.
 *
 * Roles never needed separate balance *mechanisms*: a party's receivable, its
 * payable and its loan are already three different accounts summed over the same
 * journal_lines.party_id. So this is one query shape (`balanceOn`) with a
 * different account each time — replacing the two hand-copied versions that had
 * grown in PayablesService and ReceivablesService, before a third and fourth
 * could be copied for loans and partners.
 *
 * Nothing here writes. Balances are derived from journal_lines on every call and
 * are never stored — journal_lines remains the single source of truth.
 */
class PartyLedgerService
{
    /**
     * Signed balance for one party on one account, in the account's natural
     * direction: debit-positive for assets/expenses (a receivable of 500k reads
     * +500k), credit-positive for liabilities/equity/revenue (a payable of 500k
     * reads +500k). So every figure below is "how much, in the direction this
     * account normally runs", and a negative one means the balance has flipped
     * (e.g. a supplier we have overpaid).
     */
    public function balanceOn(Party $party, AccountCode $code): int
    {
        $account = $code->account();

        $sums = JournalLine::where('account_id', $account->id)
            ->where('party_id', $party->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $debit = (int) $sums->debit;
        $credit = (int) $sums->credit;

        return in_array($account->type, ['asset', 'expense'], true)
            ? $debit - $credit
            : $credit - $debit;
    }

    /** >0: the customer owes us. */
    public function customerReceivable(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::AccountsReceivable);
    }

    /** >0: we hold credit belonging to the customer (a prepayment / unused refund). */
    public function customerCredit(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::CustomerCredit);
    }

    /** >0: we owe the supplier. */
    public function supplierPayable(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::AccountsPayable);
    }

    /**
     * >0: we have paid the supplier ahead of their invoices.
     *
     * Today nothing posts to 1450 — an overpayment still drives the payable
     * balance negative (see PaymentRecorder::pay). Until the operation that
     * splits an advance onto its own account ships, the negative part of the
     * payable IS the advance, so it is reported here rather than hidden.
     */
    public function supplierAdvance(Party $party): int
    {
        $onAdvanceAccount = $this->balanceOn($party, AccountCode::SupplierAdvance);
        $overpaidPayable = max(0, -$this->supplierPayable($party));

        return $onAdvanceAccount + $overpaidPayable;
    }

    /** >0: the employee holds an advance from us. */
    public function employeeAdvance(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::EmployeeAdvance);
    }

    /** >0: we owe the employee salary. */
    public function payrollPayable(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::PayrollPayable);
    }

    /** >0: they owe us the loan we gave them. */
    public function loanReceivable(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::LoansReceivable);
    }

    /** >0: we owe them the loan they gave us. */
    public function loanPayable(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::LoansPayable);
    }

    /** >0: we owe the partner on their current account. */
    public function partnerCurrentAccount(Party $party): int
    {
        return $this->balanceOn($party, AccountCode::PartnerCurrentAccount);
    }

    /**
     * Every balance this party currently has, keyed by context, with the zero
     * ones dropped — this is what the profile's balance cards render.
     *
     * @return array<string, array{label: string, amount: int, direction: string}>
     */
    public function balances(Party $party): array
    {
        $contexts = [
            'customer_receivable' => ['مانده دریافتنی مشتری', $this->customerReceivable($party), 'due_to_us'],
            'customer_credit' => ['اعتبار مشتری نزد ما', $this->customerCredit($party), 'due_to_them'],
            'supplier_payable' => ['مانده پرداختنی تأمین‌کننده', $this->supplierPayable($party), 'due_to_them'],
            'supplier_advance' => ['پیش‌پرداخت به تأمین‌کننده', $this->supplierAdvance($party), 'due_to_us'],
            'employee_advance' => ['مساعده کارمند', $this->employeeAdvance($party), 'due_to_us'],
            'payroll_payable' => ['حقوق پرداختنی', $this->payrollPayable($party), 'due_to_them'],
            'loan_receivable' => ['وام پرداختی', $this->loanReceivable($party), 'due_to_us'],
            'loan_payable' => ['وام دریافتی', $this->loanPayable($party), 'due_to_them'],
            'partner_current_account' => ['حساب جاری شریک', $this->partnerCurrentAccount($party), 'due_to_them'],
        ];

        $balances = [];

        foreach ($contexts as $key => [$label, $amount, $direction]) {
            // A supplier payable that has gone negative is reported as an advance
            // by supplierAdvance() — showing it twice, once as a negative payable,
            // would double-count it on the profile.
            if ($key === 'supplier_payable') {
                $amount = max(0, $amount);
            }

            if ($amount !== 0) {
                $balances[$key] = ['label' => $label, 'amount' => $amount, 'direction' => $direction];
            }
        }

        return $balances;
    }

    /**
     * The display-only net position («وضعیت خالص نمایشی»).
     *
     * Informational ONLY. It is computed in PHP from the balances above, writes
     * nothing, and settles nothing: a receivable and a payable that net to zero
     * here are both still fully outstanding in the ledger. Actually offsetting
     * them is a separate, deliberate, posted operation.
     *
     * >0: on balance they owe us. <0: on balance we owe them.
     */
    public function consolidatedPosition(Party $party): int
    {
        $net = 0;

        foreach ($this->balances($party) as $balance) {
            $net += $balance['direction'] === 'due_to_us' ? $balance['amount'] : -$balance['amount'];
        }

        return $net;
    }

    /**
     * The party's complete statement («گردش کامل حساب») — every journal line
     * carrying this party_id, across every account, newest first, with a running
     * balance in the direction of each line's own account.
     *
     * This is the one view that did not exist before: every existing ledger is
     * scoped to a single account (PayablesService::ledger is AP-only), so a party
     * that is both a customer and a supplier had no single history to read.
     */
    public function statement(Party $party, TableQuery $query): LengthAwarePaginator
    {
        $search = $query->search() ?? '';

        $lines = JournalLine::where('journal_lines.party_id', $party->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('journal_entries.description', 'like', "%{$search}%")
                ->orWhere('accounts.name', 'like', "%{$search}%")))
            ->with(['entry', 'account'])
            ->select('journal_lines.*')
            // Stable tiebreaker: two lines can share an entry_date, and a
            // statement must not reshuffle between page loads.
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_lines.id')
            ->tap(fn ($q) => $query->apply($q))
            ->paginate($query->perPage())
            ->withQueryString();

        $lines->getCollection()->transform(fn (JournalLine $line) => tap($line, function (JournalLine $l) {
            $isDebitNatural = in_array($l->account->type, ['asset', 'expense'], true);

            $l->signed_amount = $isDebitNatural
                ? $l->debit - $l->credit
                : $l->credit - $l->debit;

            $l->jalali_date = JalaliPeriod::fmtDateTime($l->entry->entry_date);
        }));

        return $lines;
    }
}
