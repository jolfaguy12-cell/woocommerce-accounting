<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Services\EmployeeAccountService;
use App\Domain\Receivables\Services\PayrollService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');

    $this->accounts = app(EmployeeAccountService::class);

    $this->party = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $this->employee = Employee::firstWhere('party_id', $this->party->id);
    $this->employee->update(['base_salary' => 12_000_000]);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
});

it('shows «حساب کارمند» with every balance card and the transaction timeline', function () {
    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    $this->actingAs($this->accountant)
        ->get(route('employees.show', $this->party))
        ->assertOk()
        ->assertSee('حقوق تحقق‌یافته')
        ->assertSee('حقوق پرداخت‌شده')
        ->assertSee('مانده حقوق')
        ->assertSee('مساعده')
        ->assertSee('پرداخت حقوق')
        ->assertSee('بازپرداخت هزینه کارمند')
        ->assertSee('گردش کامل حساب')
        // The accrual shows up in the timeline, on this employee's own identity.
        ->assertSee('حقوق دوره 1405-04');
});

it('searches the transaction timeline', function () {
    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    $this->actingAs($this->admin)
        ->get(route('employees.show', ['party' => $this->party, 'search' => 'حقوق']))
        ->assertOk()
        ->assertSee('حقوق دوره 1405-04');

    $this->actingAs($this->admin)
        ->get(route('employees.show', ['party' => $this->party, 'search' => 'چیزی-که-وجود-ندارد']))
        ->assertOk()
        ->assertSee('سندی یافت نشد');
});

it('pays salary through the form and moves «مانده حقوق»', function () {
    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    $this->actingAs($this->accountant)
        ->post(route('employees.salary-payment', $this->party), [
            'amount' => 5_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
            'method' => 'bank_transfer',
            'reference' => 'TR-100',
        ])
        ->assertRedirect();

    $summary = $this->accounts->summary($this->party->fresh());

    expect($summary['paid_salary'])->toBe(5_000_000)
        ->and($summary['salary_balance'])->toBe(7_000_000);
});

it('rejects an overpayment at the form without posting anything', function () {
    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    $this->actingAs($this->accountant)
        ->post(route('employees.salary-payment', $this->party), [
            'amount' => 20_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
            'method' => 'bank_transfer',
        ])
        ->assertSessionHasErrors('amount');

    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(12_000_000);
});

it('pays an advance without touching the salary balance', function () {
    app(PayrollService::class)->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    $this->actingAs($this->accountant)
        ->post(route('employees.advance', $this->party), [
            'amount' => 2_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
        ])
        ->assertRedirect();

    $summary = $this->accounts->summary($this->party->fresh());

    // Three separate contexts. The advance is an asset; it did not pay any salary.
    expect($summary['advances'])->toBe(2_000_000)
        ->and($summary['salary_balance'])->toBe(12_000_000)
        ->and($summary['paid_salary'])->toBe(0);
});

it('updates the employee profile without posting a journal entry', function () {
    $entries = JournalEntry::count();

    $this->actingAs($this->admin)
        ->put(route('employees.update', $this->party), [
            'base_salary' => 15_000_000,
            'job_title' => 'کارشناس انبار',
            'hired_at' => '2025-03-21',
            'is_active' => 1,
        ])
        ->assertRedirect();

    expect($this->employee->fresh()->base_salary)->toBe(15_000_000)
        ->and($this->employee->fresh()->job_title)->toBe('کارشناس انبار')
        // The contract is not a balance: changing it moves no money.
        ->and(JournalEntry::count())->toBe($entries);
});

it('404s the employee page for a party with no employee role', function () {
    $customer = Party::createWithRole('customer', ['name' => 'مشتری']);

    $this->actingAs($this->admin)
        ->get(route('employees.show', $customer))
        ->assertNotFound();
});

/** «ثبت حقوق دوره» posts the accrual, and the run page shows it. */
it('accrues a period through the payroll form', function () {
    $this->actingAs($this->accountant)
        ->post(route('payroll.store'), [
            'jalali_period' => '1405-04',
            'items' => [
                ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'advances_deducted' => 0],
            ],
        ])
        ->assertRedirect();

    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(12_000_000);

    $this->actingAs($this->accountant)
        ->get(route('payroll.index'))
        ->assertOk()
        ->assertSee('تیر 1405');
});

it('refuses a duplicate accrual through the form', function () {
    $payload = [
        'jalali_period' => '1405-04',
        'items' => [['employee_id' => $this->employee->id, 'gross' => 12_000_000]],
    ];

    $this->actingAs($this->accountant)->post(route('payroll.store'), $payload)->assertRedirect();
    $this->actingAs($this->accountant)->post(route('payroll.store'), $payload)->assertSessionHasErrors('items');

    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(12_000_000);
});

/** Payroll is sensitive financial data — the warehouse role never sees any of it. */
it('is closed to the warehouse role', function () {
    $this->actingAs($this->warehouse)->get(route('employees.index'))->assertForbidden();
    $this->actingAs($this->warehouse)->get(route('employees.show', $this->party))->assertForbidden();
    $this->actingAs($this->warehouse)->get(route('payroll.create'))->assertForbidden();

    $this->actingAs($this->warehouse)
        ->post(route('employees.salary-payment', $this->party), [
            'amount' => 1_000_000,
            'bank_account_id' => $this->bank->id,
            'accounting_date' => '2026-07-10',
        ])
        ->assertForbidden();
});
