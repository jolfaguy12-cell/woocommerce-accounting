<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PaymentPurpose;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Models\PartyPayment;
use App\Support\Design\TableQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * «حساب کارمند» — everything the company and one employee owe each other.
 *
 * It builds NOTHING of its own. There is no employee ledger, no employee balance
 * table, no second posting path: every figure below is a read of `journal_lines`
 * through PartyLedgerService, on the same Party identity the rest of the system
 * uses. The employee's salary lives on 2300, their advance on 1400, the money
 * they spent for the company on 2350, what they bought from the company on 1200,
 * and their loans on 1600/2200 — five accounts that already existed, five
 * balances that are already true.
 *
 * The contexts are kept SEPARATE on purpose. An employee who is owed 5,000,000
 * in salary, holds a 2,000,000 advance, and bought 1,200,000 of goods is not
 * "owed 1,800,000" in any sense you could act on: the salary is due on payday,
 * the advance is recovered from the next payroll run, and the goods are a
 * customer receivable. `consolidated()` prints their net position because a human
 * asks for it — it settles nothing, and it is display only. Actually offsetting
 * any two of these is a deliberate, posted operation («تهاتر»).
 */
class EmployeeAccountService
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

    /**
     * @return array{
     *     employee: ?Employee,
     *     salary: int,
     *     accrued_salary: int,
     *     paid_salary: int,
     *     salary_balance: int,
     *     advances: int,
     *     employee_paid_expenses: int,
     *     purchases_from_company: int,
     *     loan_receivable: int,
     *     loan_payable: int,
     *     consolidated: int,
     * }
     */
    public function summary(Party $party): array
    {
        $employee = $party->employee;

        return [
            'employee' => $employee,
            // The contract figure, not a balance: what they are paid per month.
            'salary' => (int) ($employee?->base_salary ?? 0),
            'accrued_salary' => $this->ledger->accruedSalary($party),
            'paid_salary' => $this->ledger->paidSalary($party),
            'salary_balance' => $this->ledger->payrollPayable($party),
            'advances' => $this->ledger->employeeAdvance($party),
            'employee_paid_expenses' => $this->ledger->employeePaidExpenses($party),
            // The employee wearing their customer hat. Same person, same Party,
            // different account — which is precisely why roles are not identities.
            'purchases_from_company' => $this->ledger->customerReceivable($party),
            'loan_receivable' => $this->ledger->loanReceivable($party),
            'loan_payable' => $this->ledger->loanPayable($party),
            'consolidated' => $this->consolidated($party),
        ];
    }

    /**
     * The rows «حساب کارمند» renders, each labelled with the context it belongs
     * to and never merged with another. A zero context is kept: "no advance" is
     * information, and a card that vanishes is a card the reader has to remember
     * to look for.
     *
     * @return array<int, array{key: string, label: string, amount: int, direction: string}>
     */
    public function contexts(Party $party): array
    {
        $s = $this->summary($party);

        return [
            ['key' => 'accrued_salary', 'label' => 'حقوق تحقق‌یافته', 'amount' => $s['accrued_salary'], 'direction' => 'neutral'],
            ['key' => 'paid_salary', 'label' => 'حقوق پرداخت‌شده', 'amount' => $s['paid_salary'], 'direction' => 'neutral'],
            ['key' => 'salary_balance', 'label' => 'مانده حقوق', 'amount' => $s['salary_balance'], 'direction' => 'due_to_them'],
            ['key' => 'advances', 'label' => 'مساعده', 'amount' => $s['advances'], 'direction' => 'due_to_us'],
            ['key' => 'employee_paid_expenses', 'label' => 'هزینه پرداخت‌شده توسط کارمند', 'amount' => $s['employee_paid_expenses'], 'direction' => 'due_to_them'],
            ['key' => 'purchases_from_company', 'label' => 'خرید از شرکت', 'amount' => $s['purchases_from_company'], 'direction' => 'due_to_us'],
            ['key' => 'loan_receivable', 'label' => 'وام پرداختی', 'amount' => $s['loan_receivable'], 'direction' => 'due_to_us'],
            ['key' => 'loan_payable', 'label' => 'وام دریافتی', 'amount' => $s['loan_payable'], 'direction' => 'due_to_them'],
        ];
    }

    /**
     * Display-only net position. Writes nothing, settles nothing.
     *
     * >0: on balance the employee owes the company. <0: the company owes them.
     * Accrued and paid salary are excluded — they are the two halves of the salary
     * balance, and counting all three would count the same salary twice.
     */
    public function consolidated(Party $party): int
    {
        $dueToUs = $this->ledger->employeeAdvance($party)
            + $this->ledger->customerReceivable($party)
            + $this->ledger->loanReceivable($party);

        $dueToThem = $this->ledger->payrollPayable($party)
            + $this->ledger->employeePaidExpenses($party)
            + $this->ledger->loanPayable($party);

        return $dueToUs - $dueToThem;
    }

    /**
     * Complete transaction history — every journal line on this identity across
     * every one of the accounts above, which is exactly the Party statement. There
     * is no employee-specific history to build; there never was.
     */
    public function history(Party $party, TableQuery $query): LengthAwarePaginator
    {
        return $this->ledger->statement($party, $query);
    }

    /**
     * «کارکنان» — every employee with the three figures the list actually needs to
     * show: what they are owed, what they owe, and what they laid out for us.
     *
     * A merged party is not listed: it is not a separate person any more, and its
     * history is already aggregated into the survivor's row.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function roster(?string $search = null)
    {
        return Employee::query()
            ->with('party.roles')
            ->whereHas('party', fn ($q) => $q->notMerged()
                ->when(filled($search), fn ($w) => $w->where(fn ($n) => $n
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('normalized_phone', 'like', "%{$search}%"))))
            ->get()
            ->filter(fn (Employee $e) => $e->party !== null)
            ->map(fn (Employee $e) => [
                'employee' => $e,
                'party' => $e->party,
                'salary' => (int) $e->base_salary,
                'salary_balance' => $this->ledger->payrollPayable($e->party),
                'advances' => $this->ledger->employeeAdvance($e->party),
                'employee_paid_expenses' => $this->ledger->employeePaidExpenses($e->party),
            ])
            ->sortBy([
                // Active staff first, then by name — a list whose top half is people
                // who left is a list nobody reads.
                fn ($a, $b) => ($b['employee']->is_active <=> $a['employee']->is_active),
                fn ($a, $b) => ($a['party']->name <=> $b['party']->name),
            ])
            ->values();
    }

    /**
     * «سوابق پرداخت حقوق» — every payment ever posted against 2300 for this
     * identity, whether it was a standalone «پرداخت حقوق» or one half of a
     * «پرداخت هم‌زمان» accrual. Newest first, with the run it was paid alongside
     * (if any) and its reversal status, so the page can offer "برگشت" on exactly
     * the rows that are still postable-against.
     *
     * A read of party_payments, not a second ledger: paidSalary() on the KPI
     * cards above sums the SAME rows straight out of journal_lines, so the two
     * can never drift apart from each other.
     *
     * @return Collection<int, PartyPayment>
     */
    public function salaryPayments(Party $party): Collection
    {
        return PartyPayment::query()
            ->whereIn('party_id', $party->identityIds())
            ->where('purpose', PaymentPurpose::PayrollPayment->value)
            ->with(['bankAccount', 'creator', 'reverser', 'applied'])
            ->latest('id')
            ->get();
    }

    /** The accounts «حساب کارمند» is made of, for the "where does this come from" note. */
    public function accountCodes(): array
    {
        return [
            AccountCode::PayrollPayable,
            AccountCode::EmployeeAdvance,
            AccountCode::EmployeeCurrentAccount,
            AccountCode::AccountsReceivable,
            AccountCode::LoansReceivable,
            AccountCode::LoansPayable,
        ];
    }
}
