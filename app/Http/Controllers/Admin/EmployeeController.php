<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Services\EmployeeAccountService;
use App\Domain\Receivables\Services\LoanService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\PayrollService;
use App\Http\Controllers\Controller;
use App\Support\Design\TableQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * «حساب کارمند» — everything the company and one employee owe each other, on one
 * page, with each debt still in its own account.
 *
 * The page is a READ of the ledger plus the handful of buttons that post to it. It
 * owns no balance of its own: «مانده حقوق» is account 2300, «مساعده» is 1400,
 * «هزینه پرداخت‌شده توسط کارمند» is 2350, and a purchase from the company is 1200
 * — five accounts that already existed, read through EmployeeAccountService on the
 * same Party identity the rest of the system uses.
 *
 * They are never netted. An employee owed 12,000,000 in salary who holds a
 * 2,000,000 advance is not "owed 10,000,000": the salary is due on payday and the
 * advance is recovered by the next payroll run. The consolidated figure on the page
 * is display-only and settles nothing.
 */
class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeAccountService $accounts,
        private readonly PayrollService $payroll,
        private readonly PaymentRecorder $payments,
        private readonly LoanService $loans,
    ) {}

    public function index(Request $request): View
    {
        $search = $request->string('search')->value() ?: null;

        return view('pages.employees.index', [
            'title' => 'کارکنان',
            'rows' => $this->accounts->roster($search),
            'search' => $search,
        ]);
    }

    /**
     * The primary employee page. A party that is not an employee lands here only by
     * URL, and is told how to become one rather than shown an empty page.
     */
    public function show(Request $request, Party $party): View|RedirectResponse
    {
        // An absorbed party is the same person as its survivor — its URL redirects
        // rather than 404s, exactly as the party profile does.
        if ($party->isMerged()) {
            return redirect()->route('employees.show', $party->canonical());
        }

        abort_unless($party->hasRole(PartyRoleType::Employee), 404);

        $employee = $party->employee ?? $party->profileFor(PartyRoleType::Employee);

        $query = new TableQuery(request: $request, sortable: [], defaultSort: '');

        return view('pages.employees.show', [
            'title' => $party->name,
            'party' => $party,
            'employee' => $employee,
            'summary' => $this->accounts->summary($party),
            'contexts' => $this->accounts->contexts($party),
            // «گردش کامل حساب» — every line on this identity, searchable, paginated.
            'statement' => $this->accounts->history($party, $query),
            'statementQuery' => $query,
            'loans' => $this->employeeLoans($party),
            'banks' => BankAccount::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'today' => Carbon::now(JalaliPeriod::TIMEZONE)->toDateString(),
            'hiredAt' => $employee?->hired_at?->toDateString(),
        ]);
    }

    /** Base salary, job title, hire date — the contract, not the balance. */
    public function updateProfile(Request $request, Party $party): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Employee), 404);

        $data = $request->validate([
            'base_salary' => 'required|integer|min:0',
            'job_title' => 'nullable|string|max:100',
            'hired_at' => 'nullable|date',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $employee = $party->employee ?? $party->profileFor(PartyRoleType::Employee);

        $employee->update([
            'base_salary' => $data['base_salary'],
            'job_title' => $data['job_title'] ?? null,
            'hired_at' => $data['hired_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('success', 'اطلاعات کارمند به‌روزرسانی شد. این تغییر هیچ سندی در دفتر ثبت نمی‌کند.');
    }

    /** «پرداخت حقوق» — capped at «مانده حقوق»; the cap lives in PaymentRecorder. */
    public function paySalary(Request $request, Party $party): RedirectResponse
    {
        $data = $this->validatePayment($request);

        try {
            $this->payroll->paySalary(
                party: $party,
                amount: $data['amount'],
                bankAccountId: $data['bank_account_id'],
                accountingDate: $data['accounting_date'],
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
                by: $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'پرداخت حقوق ثبت شد و از «مانده حقوق» کسر گردید.');
    }

    /**
     * «مساعده» — salary paid before it is earned. It is an ASSET (1400), not a
     * reduction of the salary debt: the employee owes it back, and the next payroll
     * run recovers it explicitly.
     */
    public function payAdvance(Request $request, Party $party): RedirectResponse
    {
        abort_unless($party->hasRole(PartyRoleType::Employee), 404);

        $data = $this->validatePayment($request);

        try {
            $this->payments->payEmployeeAdvance(
                party: $party,
                amount: $data['amount'],
                bankAccountId: $data['bank_account_id'],
                accountingDate: $data['accounting_date'],
                reference: $data['reference'] ?? null,
                note: $data['note'] ?? null,
                by: $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'مساعده پرداخت شد و در حساب «مساعده» کارمند نشست؛ در لیست حقوق بعدی کسر می‌شود.');
    }

    /** @return array<string, mixed> */
    private function validatePayment(Request $request): array
    {
        return $request->validate([
            'amount' => 'required|integer|min:1',
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'accounting_date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function employeeLoans(Party $party)
    {
        return Loan::whereIn('party_id', $party->identityIds())
            ->with(['bankAccount', 'installments'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Loan $loan) => [
                'loan' => $loan,
                'direction' => $loan->direction->label(),
                'principal' => (int) $loan->principal,
                'remaining_principal' => $this->loans->remainingPrincipal($loan),
                'status_label' => $loan->status->label(),
                'status' => $loan->status->badgeStatus(),
                'next_due_fa' => $loan->nextInstallment()?->due_date
                    ? JalaliPeriod::fmtDate($loan->nextInstallment()->due_date)
                    : null,
            ]);
    }
}
