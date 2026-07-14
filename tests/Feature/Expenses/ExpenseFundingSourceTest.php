<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->recorder = app(ExpenseRecorder::class);
    $this->ledger = app(PartyLedgerService::class);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->category = ExpenseCategory::create([
        'name' => 'اداری',
        'slug' => 'admin',
        'account_code' => AccountCode::OperatingExpense->value,
        'is_active' => true,
    ]);

    $this->base = fn (array $overrides = []) => $overrides + [
        'expense_category_id' => $this->category->id,
        'amount' => 500_000,
        'description' => 'خرید لوازم اداری',
        'expense_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
    ];
});

/** The behaviour that already existed, pinned so the new funding sources cannot break it. */
it('still credits the bank account for a company-paid expense', function () {
    $expense = $this->recorder->record(($this->base)([
        'funding_source' => ExpenseFundingSource::Bank->value,
        'bank_account_id' => $this->bank->id,
    ]));

    $lines = $expense->journalEntry->lines;

    expect($lines)->toHaveCount(2)
        ->and($lines->firstWhere('account_id', Account::firstWhere('code', '6000')->id)->debit)->toBe(500_000)
        ->and($lines->firstWhere('account_id', $this->bank->account_id)->credit)->toBe(500_000)
        ->and($expense->funded_by_party_id)->toBeNull();
});

/**
 * The bug this whole change exists for: an employee pays for something with their
 * own money, and the old recorder booked it as company cash leaving the bank. The
 * bank balance dropped although no company money moved, and the debt owed to the
 * employee appeared nowhere at all.
 */
it('books an employee-funded expense as a debt to the employee, not a bank withdrawal', function () {
    $employee = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $bankBefore = $this->bank->account->balance();

    $expense = $this->recorder->record(($this->base)([
        'funding_source' => ExpenseFundingSource::Employee->value,
        'funded_by_party_id' => $employee->id,
    ]));

    $lines = $expense->journalEntry->lines;
    $credit = $lines->firstWhere('account_id', AccountCode::EmployeeCurrentAccount->account()->id);

    expect($lines)->toHaveCount(2)
        ->and($credit->credit)->toBe(500_000)
        // The line carries the employee. Without it the credit lands on the right
        // account and belongs to nobody, and their balance reads zero.
        ->and($credit->party_id)->toBe($employee->id)
        ->and($expense->bank_account_id)->toBeNull()
        // The company's cash is untouched — because no company cash moved.
        ->and($this->bank->account->fresh()->balance())->toBe($bankBefore)
        ->and($this->ledger->employeePaidExpenses($employee))->toBe(500_000);
});

it('books an unpaid expense as an account payable', function () {
    $supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);

    $expense = $this->recorder->record(($this->base)([
        'funding_source' => ExpenseFundingSource::Unpaid->value,
        'funded_by_party_id' => $supplier->id,
    ]));

    $credit = $expense->journalEntry->lines
        ->firstWhere('account_id', AccountCode::AccountsPayable->account()->id);

    expect($credit->credit)->toBe(500_000)
        ->and($credit->party_id)->toBe($supplier->id)
        ->and($this->ledger->supplierPayable($supplier))->toBe(500_000);
});

it('books a partner-funded expense on the partner current account', function () {
    $partner = Party::createWithRole('partner', ['name' => 'رضا کریمی']);

    $expense = $this->recorder->record(($this->base)([
        'funding_source' => ExpenseFundingSource::Partner->value,
        'funded_by_party_id' => $partner->id,
    ]));

    expect($this->ledger->partnerCurrentAccount($partner))->toBe(500_000)
        ->and($expense->journalEntry->lines
            ->firstWhere('account_id', AccountCode::PartnerCurrentAccount->account()->id)->party_id)
        ->toBe($partner->id);
});

/**
 * Billing «حساب جاری کارمند» for somebody who is not an employee would park a debt
 * on an account nobody reads for them — it balances, and it is still lost.
 */
it('refuses to bill an employee-funded expense to a party without the employee role', function () {
    $customer = Party::createWithRole('customer', ['name' => 'مشتری معمولی']);

    expect(fn () => $this->recorder->record(($this->base)([
        'funding_source' => ExpenseFundingSource::Employee->value,
        'funded_by_party_id' => $customer->id,
    ])))->toThrow(InvalidArgumentException::class, 'کارمند');
});

it('refuses a non-bank expense with no counterparty, and a bank expense with no bank', function () {
    expect(fn () => $this->recorder->record(($this->base)([
        'funding_source' => ExpenseFundingSource::Employee->value,
    ])))->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->recorder->record(($this->base)([
        'funding_source' => ExpenseFundingSource::Bank->value,
    ])))->toThrow(InvalidArgumentException::class);
});

it('keeps every expense entry balanced whatever funded it', function () {
    $employee = Party::createWithRole('employee', ['name' => 'سارا']);
    $partner = Party::createWithRole('partner', ['name' => 'رضا']);
    $supplier = Party::createWithRole('supplier', ['name' => 'پخش']);

    $this->recorder->record(($this->base)(['funding_source' => 'bank', 'bank_account_id' => $this->bank->id]));
    $this->recorder->record(($this->base)(['funding_source' => 'employee', 'funded_by_party_id' => $employee->id]));
    $this->recorder->record(($this->base)(['funding_source' => 'partner', 'funded_by_party_id' => $partner->id]));
    $this->recorder->record(($this->base)(['funding_source' => 'unpaid', 'funded_by_party_id' => $supplier->id]));

    expect((int) JournalLine::sum('debit'))->toBe((int) JournalLine::sum('credit'))
        ->and((int) JournalLine::sum('debit'))->toBe(2_000_000);
});

it('defaults to bank funding, so an existing caller keeps working unchanged', function () {
    $expense = $this->recorder->record(($this->base)([
        'bank_account_id' => $this->bank->id,
    ]));

    expect($expense->fundingSource())->toBe(ExpenseFundingSource::Bank)
        ->and($expense->isLiability())->toBeFalse();
});
