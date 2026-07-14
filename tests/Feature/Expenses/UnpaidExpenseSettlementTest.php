<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Expenses\Services\ExpenseSettlementService;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use App\Domain\Expenses\Support\ExpenseSettlementStatus;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * «تسویه هزینه پرداخت‌نشده» — paying a bill the company recorded as owed.
 *
 * The cost was recognised on the day the expense was entered. Paying it must NOT
 * recognise it again: a settlement is a payment (Dr 2000, Cr bank), never a second
 * expense — and the second-expense mistake balances perfectly, which is exactly why
 * it survives review.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');

    $this->settlements = app(ExpenseSettlementService::class);
    $this->payments = app(PaymentRecorder::class);
    $this->ledger = app(PartyLedgerService::class);

    $this->supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    $this->category = ExpenseCategory::create([
        'name' => 'اجاره', 'slug' => 'rent',
        'account_code' => AccountCode::OperatingExpense->value, 'is_active' => true,
    ]);

    $this->unpaid = fn (int $amount = 5_000_000) => app(ExpenseRecorder::class)->record([
        'expense_category_id' => $this->category->id,
        'funding_source' => ExpenseFundingSource::Unpaid->value,
        'funded_by_party_id' => $this->supplier->id,
        'amount' => $amount,
        'description' => 'اجاره تیر',
        'expense_date' => Carbon::parse('2026-07-05', 'Asia/Tehran'),
    ]);
});

it('starts an unpaid expense as «پرداخت‌نشده» with the full amount owed', function () {
    $expense = ($this->unpaid)();

    expect($this->settlements->status($expense))->toBe(ExpenseSettlementStatus::Unpaid)
        ->and($this->settlements->remaining($expense))->toBe(5_000_000)
        ->and($this->settlements->settled($expense))->toBe(0)
        // The debt is real from day one, on the creditor's own payable.
        ->and($this->ledger->supplierPayable($this->supplier))->toBe(5_000_000);
});

it('settles part of it: reduces AP, credits the bank, and reads «بخشی پرداخت‌شده»', function () {
    $expense = ($this->unpaid)();

    $payment = $this->settlements->settle($expense, [
        'amount' => 2_000_000,
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->admin->id,
    ]);

    $ap = $payment->journalEntry->lines->firstWhere('account_id', AccountCode::AccountsPayable->account()->id);
    $cash = $payment->journalEntry->lines->firstWhere('account_id', $this->bank->account_id);

    expect((int) $ap->debit)->toBe(2_000_000)
        ->and($ap->party_id)->toBe($this->supplier->id)
        ->and((int) $cash->credit)->toBe(2_000_000)
        ->and($this->settlements->status($expense))->toBe(ExpenseSettlementStatus::Partial)
        ->and($this->settlements->remaining($expense))->toBe(3_000_000)
        ->and($this->ledger->supplierPayable($this->supplier))->toBe(3_000_000);
});

it('settles the rest and reads «پرداخت‌شده»', function () {
    $expense = ($this->unpaid)();

    $this->settlements->settle($expense, ['amount' => 2_000_000, 'bank_account_id' => $this->bank->id]);
    $this->settlements->settle($expense, ['amount' => 3_000_000, 'bank_account_id' => $this->bank->id]);

    expect($this->settlements->status($expense))->toBe(ExpenseSettlementStatus::Paid)
        ->and($this->settlements->remaining($expense))->toBe(0)
        ->and($this->ledger->supplierPayable($this->supplier))->toBe(0);
});

/**
 * The mistake this whole flow exists to prevent. Settling a bill must not recognise
 * the cost a second time — and it would balance perfectly if it did.
 */
it('creates no second expense and no second expense-account debit', function () {
    $expense = ($this->unpaid)();

    $expenseAccountId = AccountCode::OperatingExpense->account()->id;

    $this->settlements->settle($expense, ['amount' => 5_000_000, 'bank_account_id' => $this->bank->id]);

    $expenseDebits = JournalLine::where('account_id', $expenseAccountId)->sum('debit');

    expect(Expense::count())->toBe(1)
        // The cost was recognised ONCE, on the day the expense was entered.
        ->and((int) $expenseDebits)->toBe(5_000_000);
});

it('refuses to settle more than is remaining', function () {
    $expense = ($this->unpaid)();

    $this->settlements->settle($expense, ['amount' => 4_000_000, 'bank_account_id' => $this->bank->id]);

    expect(fn () => $this->settlements->settle($expense, [
        'amount' => 1_000_001, 'bank_account_id' => $this->bank->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect($this->settlements->remaining($expense))->toBe(1_000_000);
});

/** Duplicate settlement and overpayment are the same guard: the cap is what is LEFT. */
it('refuses a duplicate settlement of an already-paid expense', function () {
    $expense = ($this->unpaid)();

    $this->settlements->settle($expense, ['amount' => 5_000_000, 'bank_account_id' => $this->bank->id]);

    expect(fn () => $this->settlements->settle($expense, [
        'amount' => 5_000_000, 'bank_account_id' => $this->bank->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect($this->ledger->supplierPayable($this->supplier))->toBe(0);
});

it('refuses to settle an expense that was paid from a company account', function () {
    $expense = app(ExpenseRecorder::class)->record([
        'expense_category_id' => $this->category->id,
        'funding_source' => ExpenseFundingSource::Bank->value,
        'bank_account_id' => $this->bank->id,
        'amount' => 1_000_000,
        'description' => 'قبض برق',
        'expense_date' => Carbon::parse('2026-07-05', 'Asia/Tehran'),
    ]);

    expect(fn () => $this->settlements->settle($expense, [
        'amount' => 1_000_000, 'bank_account_id' => $this->bank->id,
    ]))->toThrow(InvalidArgumentException::class);

    // …and it is not reported as «پرداخت‌نشده» either: it was paid the day it was entered.
    expect($this->settlements->status($expense))->toBe(ExpenseSettlementStatus::Paid);
});

/**
 * The reason there is no `paid` column. A reversed settlement puts the money back on
 * the bill with nothing having to remember to un-flag anything — because the figure
 * is derived from journal lines, and the ledger now says the payment was undone.
 */
it('puts the bill back when a settlement is reversed', function () {
    $expense = ($this->unpaid)();

    $payment = $this->settlements->settle($expense, ['amount' => 5_000_000, 'bank_account_id' => $this->bank->id]);

    expect($this->settlements->status($expense))->toBe(ExpenseSettlementStatus::Paid);

    $this->payments->reverse($payment, 'پرداخت انجام نشد', $this->admin);

    expect($this->settlements->settled($expense))->toBe(0)
        ->and($this->settlements->remaining($expense))->toBe(5_000_000)
        ->and($this->settlements->status($expense))->toBe(ExpenseSettlementStatus::Unpaid)
        ->and($this->ledger->supplierPayable($this->supplier))->toBe(5_000_000)
        // The original entry was not edited — an opposing one was posted.
        ->and(JournalEntry::where('reversal_of_entry_id', $payment->journal_entry_id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| HTTP + permissions
|--------------------------------------------------------------------------
*/

it('settles through the form and shows the three states on the list', function () {
    $expense = ($this->unpaid)();

    $this->actingAs($this->accountant)
        ->get(route('expenses.index'))
        ->assertOk()
        ->assertSee('پرداخت‌نشده');

    $this->actingAs($this->accountant)
        ->post(route('expenses.settle', $expense), [
            'amount' => 2_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
            'reference' => 'TR-77',
        ])
        ->assertRedirect();

    expect($this->settlements->remaining($expense))->toBe(3_000_000);

    $this->actingAs($this->accountant)
        ->get(route('expenses.show', $expense))
        ->assertOk()
        ->assertSee('بخشی پرداخت‌شده')
        ->assertSee('تسویه هزینه پرداخت‌نشده');
});

it('rejects an over-cap settlement at the form without posting anything', function () {
    $expense = ($this->unpaid)();

    $this->actingAs($this->accountant)
        ->post(route('expenses.settle', $expense), [
            'amount' => 9_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
        ])
        ->assertSessionHasErrors('amount');

    expect($this->ledger->supplierPayable($this->supplier))->toBe(5_000_000);
});

it('is closed to the warehouse role', function () {
    $expense = ($this->unpaid)();

    $this->actingAs($this->warehouse)->get(route('expenses.index'))->assertForbidden();

    $this->actingAs($this->warehouse)
        ->post(route('expenses.settle', $expense), [
            'amount' => 1_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
        ])
        ->assertForbidden();
});
