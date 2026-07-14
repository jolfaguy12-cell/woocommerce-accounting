<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Models\PayrollRun;
use App\Domain\Receivables\Services\PayrollService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * «ثبت حقوق دوره» — accruing a period's salaries, and nothing more.
 *
 * The form asks for a period and a gross figure per employee. It does not ask for
 * attendance, overtime, tax or insurance, and it should not learn to: those belong
 * to an HR system, and every one of them added here would be a number this
 * application cannot verify and would have to take on trust.
 *
 * Payment is a separate screen («پرداخت حقوق», on the employee's page), because it
 * is a separate event: the accrual says the salary was earned, the payment says the
 * money was handed over, and a month where the first happened and the second did not
 * is the normal case, not an error.
 */
class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly PartyLedgerService $ledger,
    ) {}

    public function index(): View
    {
        return view('pages.payroll.index', [
            'title' => 'لیست‌های حقوق',
            'runs' => PayrollRun::with(['items.employee.party', 'creator'])
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function create(Request $request): View
    {
        $period = $request->string('period')->value() ?: JalaliPeriod::current();

        $employees = $this->payroll->payableEmployees()->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->party->name,
            'job_title' => $e->job_title,
            // The contract figure the row is PROPOSED from — the operator can change it,
            // and what they type is what gets accrued.
            'base_salary' => (int) $e->base_salary,
            // How much advance they are actually holding. The accrual may not recover
            // more than this: crediting 1400 below what was taken would make their
            // advance negative, i.e. the company owing them an advance.
            'advance_held' => max(0, $this->ledger->employeeAdvance($e->party)),
            'already_accrued' => $this->payroll->alreadyAccrued($e->id, $period),
        ]);

        return view('pages.payroll.create', [
            'title' => 'ثبت حقوق دوره',
            'period' => $period,
            'employees' => $employees,
            'periods' => JalaliPeriod::recent(12),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'jalali_period' => 'required|string|max:7',
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.employee_id' => 'required|integer|exists:employees,id',
            'items.*.gross' => 'required|integer|min:1',
            'items.*.advances_deducted' => 'nullable|integer|min:0',
        ], [
            'items.required' => 'حداقل یک کارمند را برای ثبت حقوق انتخاب کنید.',
        ]);

        try {
            $run = $this->payroll->post(
                $data['jalali_period'],
                array_values($data['items']),
                $request->user()->id,
                $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException|PeriodLockedException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()->route('payroll.show', $run)
            ->with('success', 'حقوق دوره ثبت شد. مبلغ هر کارمند در «مانده حقوق» خودش نشست؛ هنوز وجهی پرداخت نشده است.');
    }

    public function show(PayrollRun $run): View
    {
        $run->load(['items.employee.party', 'journalEntry.lines.account', 'creator', 'reverser']);

        return view('pages.payroll.show', [
            'title' => "حقوق دوره {$run->jalali_period}",
            'run' => $run,
            // What each employee on this run is owed TODAY — which is not what the run
            // accrued, once any of it has been paid.
            'balances' => $run->items->mapWithKeys(fn ($item) => [
                $item->id => $item->employee?->party
                    ? $this->ledger->payrollPayable($item->employee->party)
                    : 0,
            ]),
        ]);
    }

    /** Corrections are reversals — the posted run is never edited and never deleted. */
    public function reverse(Request $request, PayrollRun $run): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255'], [
            'reason.required' => 'دلیل برگشت باید ثبت شود.',
        ]);

        try {
            $this->payroll->reverse($run, $data['reason'], $request->user());
        } catch (OperationStateException|PeriodLockedException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'لیست حقوق برگشت خورد. سند اصلی دست‌نخورده ماند و سند معکوس آن ثبت شد.');
    }
}
