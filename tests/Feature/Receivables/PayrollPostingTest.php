<?php

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Models\PayrollRun;
use App\Domain\Receivables\Services\EmployeeAccountService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\PayrollService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->payroll = app(PayrollService::class);
    $this->payments = app(PaymentRecorder::class);
    $this->accounts = app(EmployeeAccountService::class);

    $this->party = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $this->employee = Employee::firstWhere('party_id', $this->party->id);
    $this->employee->update(['base_salary' => 12_000_000]);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    $this->accrue = fn (int $gross = 12_000_000, string $period = '1405-04', int $advance = 0) => $this->payroll->post($period, [
        ['employee_id' => $this->employee->id, 'gross' => $gross, 'advances_deducted' => $advance],
    ], $this->admin->id);
});

/*
|--------------------------------------------------------------------------
| Accrual — «ثبت حقوق دوره»
|--------------------------------------------------------------------------
*/

it('accrues salary as Dr salary expense / Cr payroll payable, with the employee on the payable line', function () {
    $run = ($this->accrue)();

    $expense = $run->journalEntry->lines->firstWhere('account_id', AccountCode::Payroll->account()->id);
    $payable = $run->journalEntry->lines->firstWhere('account_id', AccountCode::PayrollPayable->account()->id);

    expect((int) $expense->debit)->toBe(12_000_000)
        ->and((int) $payable->credit)->toBe(12_000_000)
        // The whole point: the payable names WHOSE salary it is. Without this the entry
        // balances and every individual «مانده حقوق» reads zero.
        ->and($payable->party_id)->toBe($this->party->id)
        ->and($run->status)->toBe(PayrollRun::POSTED);
});

it('never posts an employee payable line without a party_id', function () {
    ($this->accrue)();

    $unattributed = JournalLine::where('account_id', AccountCode::PayrollPayable->account()->id)
        ->whereNull('party_id')
        ->count();

    expect($unattributed)->toBe(0);
});

it('refuses a duplicate accrual for the same employee in the same period', function () {
    ($this->accrue)();

    expect(fn () => ($this->accrue)())
        ->toThrow(InvalidArgumentException::class);

    // The doubled salary never reached the ledger.
    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(12_000_000);
});

it('allows the same employee to be accrued again in a DIFFERENT period', function () {
    ($this->accrue)(period: '1405-04');
    ($this->accrue)(period: '1405-05');

    expect($this->accounts->summary($this->party->fresh())['accrued_salary'])->toBe(24_000_000);
});

it('refuses the same employee twice inside one run', function () {
    expect(fn () => $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ]))->toThrow(InvalidArgumentException::class);

    expect(PayrollRun::count())->toBe(0); // validated before a single row was written
});

/**
 * An advance is recovered from the advance the employee actually HOLDS. Crediting
 * 1400 below what they took would turn their advance negative — which reads as the
 * company owing them an advance, a debt that does not exist.
 */
it('will not recover more advance than the employee is holding', function () {
    expect(fn () => ($this->accrue)(advance: 2_000_000))
        ->toThrow(InvalidArgumentException::class);
});

it('recovers a real advance from the accrual and leaves the advance account at zero', function () {
    $this->payments->payEmployeeAdvance($this->party, 2_000_000, $this->bank->id);

    expect($this->accounts->summary($this->party)['advances'])->toBe(2_000_000);

    ($this->accrue)(advance: 2_000_000);

    $summary = $this->accounts->summary($this->party->fresh());

    expect($summary['advances'])->toBe(0)              // recovered in full
        ->and($summary['accrued_salary'])->toBe(10_000_000) // net accrued to 2300
        ->and($summary['salary_balance'])->toBe(10_000_000)
        ->and((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'));
});

/*
|--------------------------------------------------------------------------
| Payment — «پرداخت حقوق»
|--------------------------------------------------------------------------
*/

it('pays salary as Dr the employee payroll payable / Cr the selected bank', function () {
    ($this->accrue)();

    $payment = $this->payroll->paySalary($this->party, 5_000_000, $this->bank->id, by: $this->admin->id);

    $payable = $payment->journalEntry->lines->firstWhere('account_id', AccountCode::PayrollPayable->account()->id);
    $cash = $payment->journalEntry->lines->firstWhere('account_id', $this->bank->account_id);

    expect((int) $payable->debit)->toBe(5_000_000)
        ->and($payable->party_id)->toBe($this->party->id)
        ->and((int) $cash->credit)->toBe(5_000_000);

    $summary = $this->accounts->summary($this->party->fresh());

    expect($summary['accrued_salary'])->toBe(12_000_000) // «حقوق تحقق‌یافته» — unchanged
        ->and($summary['paid_salary'])->toBe(5_000_000)  // «حقوق پرداخت‌شده»
        ->and($summary['salary_balance'])->toBe(7_000_000); // «مانده حقوق»
});

it('refuses to pay more salary than the employee is owed', function () {
    ($this->accrue)();

    expect(fn () => $this->payroll->paySalary($this->party, 12_000_001, $this->bank->id))
        ->toThrow(InvalidArgumentException::class);

    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(12_000_000);
});

/** Two full payments of the same salary is the duplicate case: the second has nothing to pay. */
it('refuses a duplicate salary payment once the balance is cleared', function () {
    ($this->accrue)();

    $this->payroll->paySalary($this->party, 12_000_000, $this->bank->id);

    expect(fn () => $this->payroll->paySalary($this->party, 12_000_000, $this->bank->id))
        ->toThrow(InvalidArgumentException::class);

    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(0);
});

it('refuses to pay salary to someone who is not an employee', function () {
    $customer = Party::createWithRole('customer', ['name' => 'مشتری']);

    expect(fn () => $this->payroll->paySalary($customer, 1_000_000, $this->bank->id))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Reversal — the only correction there is
|--------------------------------------------------------------------------
*/

it('reverses a payroll run without touching the original entry', function () {
    $run = ($this->accrue)();

    $originalLines = $run->journalEntry->lines
        ->map(fn ($l) => [$l->id, $l->party_id, (int) $l->debit, (int) $l->credit])->all();

    $this->payroll->reverse($run, 'مبلغ اشتباه وارد شده بود', $this->admin);

    $run = $run->fresh(['journalEntry.lines']);

    expect($run->status)->toBe(PayrollRun::REVERSED)
        ->and($run->reversal_reason)->toBe('مبلغ اشتباه وارد شده بود')
        ->and($run->reversed_by)->toBe($this->admin->id)
        // The original entry's lines are byte-for-byte what they were.
        ->and($run->journalEntry->lines->map(fn ($l) => [$l->id, $l->party_id, (int) $l->debit, (int) $l->credit])->all())
        ->toBe($originalLines)
        // …and the salary debt is gone, because an opposing entry cancelled it.
        ->and($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(0);
});

it('refuses to reverse a run whose salary has already been paid', function () {
    $run = ($this->accrue)();

    $this->payroll->paySalary($this->party, 4_000_000, $this->bank->id);

    expect(fn () => $this->payroll->reverse($run, 'اشتباه', $this->admin))
        ->toThrow(OperationStateException::class);

    expect($run->fresh()->status)->toBe(PayrollRun::POSTED);
});

it('reverses a salary payment and puts the balance back', function () {
    ($this->accrue)();

    $payment = $this->payroll->paySalary($this->party, 5_000_000, $this->bank->id);

    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(7_000_000);

    $this->payments->reverse($payment, 'به حساب اشتباه واریز شد', $this->admin);

    $summary = $this->accounts->summary($this->party->fresh());

    expect($payment->fresh()->isReversed())->toBeTrue()
        ->and($summary['salary_balance'])->toBe(12_000_000)
        // «حقوق پرداخت‌شده» un-counts itself: the reversal credited 2300 back.
        ->and($summary['paid_salary'])->toBe(0);
});

it('refuses to reverse the same payment twice', function () {
    ($this->accrue)();

    $payment = $this->payroll->paySalary($this->party, 5_000_000, $this->bank->id);
    $this->payments->reverse($payment, 'اشتباه', $this->admin);

    expect(fn () => $this->payments->reverse($payment->fresh(), 'دوباره', $this->admin))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Contexts stay separate
|--------------------------------------------------------------------------
*/

it('keeps salary, advance and employee-paid expenses in three separate balances', function () {
    $this->payments->payEmployeeAdvance($this->party, 2_000_000, $this->bank->id);
    ($this->accrue)();

    $summary = $this->accounts->summary($this->party->fresh());

    // The advance did NOT reduce the salary debt, and the salary did not net the advance.
    expect($summary['salary_balance'])->toBe(12_000_000)
        ->and($summary['advances'])->toBe(2_000_000)
        // Only the display-only figure nets them, and it settles nothing.
        ->and($summary['consolidated'])->toBe(-10_000_000);
});
