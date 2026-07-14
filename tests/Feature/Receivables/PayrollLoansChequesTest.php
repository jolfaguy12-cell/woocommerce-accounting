<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Services\ChequeService;
use App\Domain\Receivables\Services\LoanService;
use App\Domain\Receivables\Services\PayrollService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
});

function accountBalance(string $code): int
{
    $account = Account::firstWhere('code', $code);

    return (int) $account->lines()->sum('debit') - (int) $account->lines()->sum('credit');
}

it('posts a payroll run with advance deductions', function () {
    $party = Party::createWithRole('employee', ['name' => 'کارمند انبار']);
    // Activating the employee role already creates the party's employee profile,
    // so this fills it in rather than inserting a second row.
    $employee = Employee::updateOrCreate(['party_id' => $party->id], ['base_salary' => 30_000_000]);

    $run = app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $employee->id, 'gross' => 30_000_000, 'advances_deducted' => 5_000_000],
    ]);

    $entry = $run->journalEntry;

    expect($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'))
        ->and(accountBalance('6100'))->toBe(30_000_000)   // salary expense
        ->and(accountBalance('1400'))->toBe(-5_000_000)   // advance recovered
        ->and(accountBalance('2300'))->toBe(-25_000_000); // net payable
});

it('registers a loan and pays an installment splitting principal and interest', function () {
    $lender = Party::createWithRole('other', ['name' => 'بانک وام‌دهنده']);
    $service = app(LoanService::class);

    $loan = $service->receive($lender, 100_000_000, $this->bank->id, Carbon::now('Asia/Tehran'));
    expect(accountBalance('2200'))->toBe(-100_000_000);

    $service->payInstallment($loan, 10_000_000, 8_500_000, $this->bank->id, Carbon::now('Asia/Tehran'));

    expect(accountBalance('2200'))->toBe(-91_500_000)   // principal reduced
        ->and(accountBalance('6300'))->toBe(1_500_000); // interest expense
});

it('clears a receivable cheque into the bank', function () {
    $customer = Party::createWithRole('customer', ['name' => 'مشتری']);
    $service = app(ChequeService::class);

    $cheque = $service->registerReceivable($customer, 5_000_000, Carbon::now('Asia/Tehran')->addDays(30));
    expect(accountBalance('1250'))->toBe(5_000_000);

    $service->clear($cheque, $this->bank->id);
    expect($cheque->refresh()->status)->toBe('cleared')
        ->and(accountBalance('1250'))->toBe(0)
        ->and(accountBalance($this->bank->account->code))->toBe(5_000_000);
});

it('returns a bounced cheque to accounts receivable', function () {
    $customer = Party::createWithRole('customer', ['name' => 'مشتری']);
    $service = app(ChequeService::class);

    $cheque = $service->registerReceivable($customer, 5_000_000, Carbon::now('Asia/Tehran')->addDays(30));
    $service->bounce($cheque);

    expect($cheque->refresh()->status)->toBe('bounced')
        ->and(accountBalance('1250'))->toBe(0)
        ->and(accountBalance('1200'))->toBe(0); // debited back where it started (net zero vs original credit)
});

it('cheque lifecycle transitions are guarded', function () {
    $customer = Party::createWithRole('customer', ['name' => 'مشتری']);
    $service = app(ChequeService::class);

    $cheque = $service->registerReceivable($customer, 1_000_000, Carbon::now('Asia/Tehran'));
    $service->clear($cheque, $this->bank->id);

    $service->bounce($cheque->refresh());
})->throws(InvalidArgumentException::class);
