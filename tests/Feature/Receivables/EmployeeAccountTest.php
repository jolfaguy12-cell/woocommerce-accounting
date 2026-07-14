<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Services\EmployeeAccountService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\PayrollService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accounts = app(EmployeeAccountService::class);

    $this->party = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $this->employee = Employee::firstWhere('party_id', $this->party->id);
    $this->employee->update(['base_salary' => 12_000_000]);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
});

/**
 * The defect this fixes: payroll posted ONE aggregate payable line with no
 * party_id. The company's total salary debt was right, and every individual
 * employee's «مانده حقوق» read zero — because not one journal line said whose
 * salary it was. An unattributable balance is not a balance.
 */
it('attributes payroll to the employee, so their salary balance is real', function () {
    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'advances_deducted' => 0],
    ]);

    $summary = $this->accounts->summary($this->party);

    expect($summary['accrued_salary'])->toBe(12_000_000)   // حقوق تحقق‌یافته
        ->and($summary['paid_salary'])->toBe(0)             // حقوق پرداخت‌شده
        ->and($summary['salary_balance'])->toBe(12_000_000) // مانده حقوق
        ->and($summary['salary'])->toBe(12_000_000);        // the contract figure

    $payable = JournalLine::where('account_id', AccountCode::PayrollPayable->account()->id)->first();

    expect($payable->party_id)->toBe($this->party->id);
});

it('keeps a two-employee payroll balanced with one payable line each', function () {
    $other = Party::createWithRole('employee', ['name' => 'رضا']);
    $otherEmployee = Employee::firstWhere('party_id', $other->id);

    // The advance has to EXIST before it can be recovered. Recovering one the employee
    // never took would credit 1400 below zero — reading as the company owing them an
    // advance, a debt that does not exist — so PayrollService now refuses it.
    app(PaymentRecorder::class)->payEmployeeAdvance($this->party, 2_000_000, $this->bank->id);

    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'advances_deducted' => 2_000_000],
        ['employee_id' => $otherEmployee->id, 'gross' => 8_000_000, 'advances_deducted' => 0],
    ]);

    expect((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'))
        ->and($this->accounts->summary($this->party)['salary_balance'])->toBe(10_000_000)
        ->and($this->accounts->summary($other)['salary_balance'])->toBe(8_000_000)
        // The advance is recovered from THIS employee, not from the payroll at large —
        // and it is now fully recovered, so their advance balance is back to zero.
        ->and($this->accounts->summary($this->party)['advances'])->toBe(0);
});

/** «هزینه پرداخت‌شده توسط کارمند» is its own context and must never leak into the salary. */
it('keeps employee-paid expenses separate from the salary balance', function () {
    $category = ExpenseCategory::create([
        'name' => 'اداری', 'slug' => 'admin',
        'account_code' => AccountCode::OperatingExpense->value, 'is_active' => true,
    ]);

    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'advances_deducted' => 0],
    ]);

    app(ExpenseRecorder::class)->record([
        'expense_category_id' => $category->id,
        'funding_source' => ExpenseFundingSource::Employee->value,
        'funded_by_party_id' => $this->party->id,
        'amount' => 800_000,
        'description' => 'تاکسی و پست',
        'expense_date' => Carbon::parse('2026-07-05', 'Asia/Tehran'),
    ]);

    $summary = $this->accounts->summary($this->party);

    expect($summary['employee_paid_expenses'])->toBe(800_000)
        // The salary balance is untouched: a reimbursement is not salary, and
        // netting the two would make «مانده حقوق» a number that means neither.
        ->and($summary['salary_balance'])->toBe(12_000_000)
        // The display-only net: we owe 12,000,000 salary + 800,000 expenses.
        ->and($summary['consolidated'])->toBe(-12_800_000);
});

it('computes every figure from the ledger and stores nothing', function () {
    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'advances_deducted' => 0],
    ]);

    $entries = JournalEntry::count();
    $lines = JournalLine::count();

    $this->accounts->summary($this->party);
    $this->accounts->contexts($this->party);
    $this->accounts->consolidated($this->party);

    expect(JournalEntry::count())->toBe($entries)
        ->and(JournalLine::count())->toBe($lines);
});

it('shows «حساب کارمند» on the party profile, and only for an employee', function () {
    $this->actingAs($this->admin)
        ->get(route('parties.show', ['party' => $this->party, 'tab' => 'employee']))
        ->assertOk()
        ->assertSee('حساب کارمند')
        ->assertSee('حقوق تحقق‌یافته')
        ->assertSee('حقوق پرداخت‌شده')
        ->assertSee('مانده حقوق')
        ->assertSee('مساعده')
        ->assertSee('هزینه پرداخت‌شده توسط کارمند');

    $customer = Party::createWithRole('customer', ['name' => 'مشتری']);

    // No employee role → no employee tab. An empty «حساب کارمند» on a customer is
    // a question the page cannot answer.
    $this->actingAs($this->admin)
        ->get(route('parties.show', $customer))
        ->assertOk()
        ->assertDontSee('حساب کارمند');
});
