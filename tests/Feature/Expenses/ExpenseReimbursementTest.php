<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Models\ExpenseCategory;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Expenses\Services\ExpenseRecorder;
use App\Domain\Expenses\Support\ExpenseFundingSource;
use App\Domain\Expenses\Support\ReimbursementType;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * «بازپرداخت هزینه کارمند» / «بازپرداخت هزینه شریک».
 *
 * The expense books the debt on the day it is incurred and touches NO bank account,
 * because no company money moved. The reimbursement is the other half — the day we
 * hand the money back — and it debits the very account the expense credited.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');

    $this->payments = app(PaymentRecorder::class);
    $this->ledger = app(PartyLedgerService::class);

    $this->employee = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $this->partner = Party::createWithRole('partner', ['name' => 'رضا شریکی']);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    $this->category = ExpenseCategory::create([
        'name' => 'اداری', 'slug' => 'admin',
        'account_code' => AccountCode::OperatingExpense->value, 'is_active' => true,
    ]);

    $this->spend = fn (Party $party, ExpenseFundingSource $source, int $amount) => app(ExpenseRecorder::class)->record([
        'expense_category_id' => $this->category->id,
        'funding_source' => $source->value,
        'funded_by_party_id' => $party->id,
        'amount' => $amount,
        'description' => 'تاکسی و پست',
        'expense_date' => Carbon::parse('2026-07-05', 'Asia/Tehran'),
    ]);
});

it('reimburses an employee by debiting 2350 and crediting the bank', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);

    expect($this->ledger->employeePaidExpenses($this->employee))->toBe(800_000);

    $payment = $this->payments->reimburse(
        ReimbursementType::Employee, $this->employee, 800_000, $this->bank->id, by: $this->admin->id
    );

    $debt = $payment->journalEntry->lines->firstWhere('account_id', AccountCode::EmployeeCurrentAccount->account()->id);
    $cash = $payment->journalEntry->lines->firstWhere('account_id', $this->bank->account_id);

    expect((int) $debt->debit)->toBe(800_000)
        ->and($debt->party_id)->toBe($this->employee->id)
        ->and((int) $cash->credit)->toBe(800_000)
        // The debt is gone because it was PAID — not because a flag was flipped.
        ->and($this->ledger->employeePaidExpenses($this->employee))->toBe(0);
});

it('reimburses a partner by debiting 2600 and crediting the bank', function () {
    ($this->spend)($this->partner, ExpenseFundingSource::Partner, 1_500_000);

    expect($this->ledger->partnerCurrentAccount($this->partner))->toBe(1_500_000);

    $payment = $this->payments->reimburse(
        ReimbursementType::Partner, $this->partner, 1_500_000, $this->bank->id, by: $this->admin->id
    );

    $debt = $payment->journalEntry->lines->firstWhere('account_id', AccountCode::PartnerCurrentAccount->account()->id);

    expect((int) $debt->debit)->toBe(1_500_000)
        ->and($debt->party_id)->toBe($this->partner->id)
        ->and($this->ledger->partnerCurrentAccount($this->partner))->toBe(0);
});

/** Partial reimbursement is a normal case: a dozen small expenses, one payment back. */
it('supports partial reimbursement and leaves the rest outstanding', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 200_000);

    $this->payments->reimburse(ReimbursementType::Employee, $this->employee, 600_000, $this->bank->id);

    expect($this->ledger->employeePaidExpenses($this->employee))->toBe(400_000);
});

it('refuses to reimburse more than the outstanding balance', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);

    expect(fn () => $this->payments->reimburse(
        ReimbursementType::Employee, $this->employee, 800_001, $this->bank->id
    ))->toThrow(InvalidArgumentException::class);

    expect($this->ledger->employeePaidExpenses($this->employee))->toBe(800_000);
});

/** The duplicate case: the second full reimbursement finds nothing left to pay. */
it('refuses a duplicate reimbursement once the balance is cleared', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);

    $this->payments->reimburse(ReimbursementType::Employee, $this->employee, 800_000, $this->bank->id);

    expect(fn () => $this->payments->reimburse(
        ReimbursementType::Employee, $this->employee, 800_000, $this->bank->id
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses to reimburse a party that does not hold the required role', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);

    // The employee has an outstanding 2350 balance — but «بازپرداخت هزینه شریک»
    // debits 2600, and they are not a partner.
    expect(fn () => $this->payments->reimburse(
        ReimbursementType::Partner, $this->employee, 800_000, $this->bank->id
    ))->toThrow(InvalidArgumentException::class);
});

it('reverses a reimbursement and puts the debt back', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);

    $payment = $this->payments->reimburse(ReimbursementType::Employee, $this->employee, 800_000, $this->bank->id);

    expect($this->ledger->employeePaidExpenses($this->employee))->toBe(0);

    $this->payments->reverse($payment, 'به حساب اشتباه واریز شد', $this->admin);

    expect($payment->fresh()->isReversed())->toBeTrue()
        ->and($this->ledger->employeePaidExpenses($this->employee))->toBe(800_000);
});

/*
|--------------------------------------------------------------------------
| HTTP + permissions
|--------------------------------------------------------------------------
*/

it('posts an employee reimbursement through the form', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);

    $this->actingAs($this->accountant)
        ->post(route('expenses.reimbursements.store'), [
            'type' => 'employee',
            'party_id' => $this->employee->id,
            'amount' => 500_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
            'reference' => 'TR-991',
            'notes' => 'بازپرداخت نقدی',
        ])
        ->assertRedirect();

    expect($this->ledger->employeePaidExpenses($this->employee))->toBe(300_000);
});

it('rejects an over-cap reimbursement at the form, without posting anything', function () {
    ($this->spend)($this->employee, ExpenseFundingSource::Employee, 800_000);

    $this->actingAs($this->accountant)
        ->post(route('expenses.reimbursements.store'), [
            'type' => 'employee',
            'party_id' => $this->employee->id,
            'amount' => 5_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
        ])
        ->assertSessionHasErrors('amount');

    expect($this->ledger->employeePaidExpenses($this->employee))->toBe(800_000);
});

it('shows the reimbursement form with the Persian labels', function () {
    $this->actingAs($this->admin)
        ->get(route('expenses.reimbursements.create', ['type' => 'employee', 'party' => $this->employee->id]))
        ->assertOk()
        ->assertSee('بازپرداخت هزینه کارمند')
        ->assertSee('بازپرداخت هزینه شریک')
        ->assertSee('هزینه پرداخت‌شده توسط کارمند');
});

/** Payroll and employee balances are sensitive financial data — warehouse never sees them. */
it('is closed to the warehouse role', function () {
    $this->actingAs($this->warehouse)
        ->get(route('expenses.reimbursements.create'))
        ->assertForbidden();

    $this->actingAs($this->warehouse)
        ->post(route('expenses.reimbursements.store'), [
            'type' => 'employee',
            'party_id' => $this->employee->id,
            'amount' => 100_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
        ])
        ->assertForbidden();
});
