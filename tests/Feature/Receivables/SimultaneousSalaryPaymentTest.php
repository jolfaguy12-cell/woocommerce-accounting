<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Employee;
use App\Domain\Receivables\Models\PayrollRun;
use App\Domain\Receivables\Services\EmployeeAccountService;
use App\Domain\Receivables\Services\PaymentRecorder;
use App\Domain\Receivables\Services\PayrollService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

/**
 * «پرداخت هم‌زمان» — an optional payment posted ALONGSIDE the accrual, atomic
 * with it but never merged into it: two journal entries, one correlation id.
 *
 * The invariant these tests exist to protect: salary expense (6100) is NEVER
 * debited straight to a bank account. 2300 is the one bridge between "earned"
 * and "paid", accrual or no accrual, simultaneous payment or none.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');

    $this->payroll = app(PayrollService::class);
    $this->payments = app(PaymentRecorder::class);
    $this->accounts = app(EmployeeAccountService::class);

    $this->party = Party::createWithRole('employee', ['name' => 'سارا محمدی']);
    $this->employee = Employee::firstWhere('party_id', $this->party->id);
    $this->employee->update(['base_salary' => 12_000_000]);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    $this->immediatePayment = fn (int $amount = 12_000_000) => [
        'amount' => $amount,
        'bank_account_id' => $this->bank->id,
        'accounting_date' => '2026-07-10',
        'method' => 'bank_transfer',
        'reference' => 'TR-1',
        'note' => 'پرداخت فوری',
    ];
});

it('posts the accrual and the simultaneous payment as two separate entries sharing one correlation id', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);

    expect(JournalEntry::count())->toBe(2)
        ->and($run->journalEntry->correlation_id)->toBe($run->uuid);

    $payment = $run->payments->first();

    expect($payment)->not->toBeNull()
        ->and($payment->journalEntry->correlation_id)->toBe($run->uuid)
        ->and($payment->journal_entry_id)->not->toBe($run->journal_entry_id)
        ->and($payment->applied_id)->toBe($run->id)
        ->and($payment->applied_type)->toBe('payroll_run');

    $summary = $this->accounts->summary($this->party->fresh());

    expect($summary['accrued_salary'])->toBe(12_000_000)
        ->and($summary['paid_salary'])->toBe(12_000_000)
        ->and($summary['salary_balance'])->toBe(0);
});

it('never debits salary expense straight to a bank account — 2300 is always the bridge', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);

    $bankLedgerAccountId = $this->bank->account_id;

    // The accrual entry never touches the bank at all.
    expect(JournalLine::where('journal_entry_id', $run->journal_entry_id)
        ->where('account_id', $bankLedgerAccountId)->exists())->toBeFalse();

    $payment = $run->payments->first();
    $bankLine = JournalLine::where('journal_entry_id', $payment->journal_entry_id)
        ->where('account_id', $bankLedgerAccountId)->first();

    expect($bankLine)->not->toBeNull()
        ->and((int) $bankLine->credit)->toBe(12_000_000);
});

it('allows a partial simultaneous payment, leaving the rest as «مانده حقوق»', function () {
    $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)(5_000_000)],
    ], $this->admin->id);

    $summary = $this->accounts->summary($this->party->fresh());

    expect($summary['paid_salary'])->toBe(5_000_000)
        ->and($summary['salary_balance'])->toBe(7_000_000);
});

it('accrues with no bank account at all when «پرداخت هم‌زمان» is not requested', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    expect($run->payments)->toBeEmpty()
        ->and(JournalEntry::count())->toBe(1);
});

it('refuses an immediate payment above this run\'s own net', function () {
    expect(fn () => $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)(12_000_001)],
    ]))->toThrow(InvalidArgumentException::class);

    expect(PayrollRun::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

/** Atomicity: if the payment cannot post, the accrual it would have ridden along with never posts either. */
it('is atomic — an invalid simultaneous payment rolls back the accrual too', function () {
    expect(fn () => $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)(99_000_000)],
    ]))->toThrow(InvalidArgumentException::class);

    expect(PayrollRun::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0)
        ->and($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(0);
});

it('requires every field of «پرداخت هم‌زمان» once it is enabled — a partial sub-form is refused', function () {
    $incomplete = ($this->immediatePayment)();
    unset($incomplete['method']);

    expect(fn () => $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => $incomplete],
    ]))->toThrow(InvalidArgumentException::class);

    expect(PayrollRun::count())->toBe(0);
});

it('refuses «پرداخت هم‌زمان» when nothing was actually accrued net of the advance deduction', function () {
    $this->payments->payEmployeeAdvance($this->party, 12_000_000, $this->bank->id);

    expect(fn () => $this->payroll->post('1405-04', [
        [
            'employee_id' => $this->employee->id, 'gross' => 12_000_000, 'advances_deducted' => 12_000_000,
            'immediate_payment' => ($this->immediatePayment)(),
        ],
    ]))->toThrow(InvalidArgumentException::class);
});

it('handles two employees in one run, each with an independent «پرداخت هم‌زمان»', function () {
    $other = Party::createWithRole('employee', ['name' => 'رضا']);
    $otherEmployee = Employee::firstWhere('party_id', $other->id);

    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)(12_000_000)],
        ['employee_id' => $otherEmployee->id, 'gross' => 8_000_000],
    ], $this->admin->id);

    expect($run->payments)->toHaveCount(1)
        ->and($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(0)
        ->and($this->accounts->summary($other->fresh())['salary_balance'])->toBe(8_000_000);
});

it('reverses a simultaneous payment independently of its run', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);

    $payment = $run->payments->first();

    $this->payments->reverse($payment, 'به حساب اشتباه واریز شد', $this->admin);

    expect($payment->fresh()->isReversed())->toBeTrue()
        ->and($run->fresh()->status)->toBe(PayrollRun::POSTED)
        ->and($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(12_000_000);
});

/*
|--------------------------------------------------------------------------
| HTTP
|--------------------------------------------------------------------------
*/

it('posts an accrual with «پرداخت هم‌زمان» through the payroll form', function () {
    $this->actingAs($this->accountant)
        ->post(route('payroll.store'), [
            'jalali_period' => '1405-04',
            'items' => [[
                'employee_id' => $this->employee->id,
                'gross' => 12_000_000,
                'pay_now' => '1',
                'payment_amount' => 12_000_000,
                'payment_bank_account_id' => $this->bank->id,
                'payment_date' => '2026-07-10',
                'payment_method' => 'bank_transfer',
                'payment_reference' => 'TR-1',
                'payment_note' => 'پرداخت فوری',
            ]],
        ])
        ->assertRedirect();

    expect($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(0);
});

it('rejects an incomplete «پرداخت هم‌زمان» at the form without posting anything', function () {
    $this->actingAs($this->accountant)
        ->post(route('payroll.store'), [
            'jalali_period' => '1405-04',
            'items' => [[
                'employee_id' => $this->employee->id,
                'gross' => 12_000_000,
                'pay_now' => '1',
                'payment_amount' => 12_000_000,
                // bank account, date, method, reference and note all missing.
            ]],
        ])
        ->assertSessionHasErrors();

    expect(PayrollRun::count())->toBe(0);
});

it('renders «سوابق پرداخت حقوق» on both the run page and the employee page', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);

    $this->actingAs($this->admin)->get(route('payroll.show', $run))
        ->assertOk()
        ->assertSee('سوابق پرداخت حقوق')
        ->assertSee('TR-1');

    $this->actingAs($this->admin)->get(route('employees.show', $this->party))
        ->assertOk()
        ->assertSee('سوابق پرداخت حقوق')
        ->assertSee('TR-1');
});

it('reverses a salary payment from the party-payments endpoint', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);

    $payment = $run->payments->first();

    $this->actingAs($this->accountant)
        ->post(route('party-payments.reverse', $payment), ['reason' => 'به حساب اشتباه واریز شد'])
        ->assertRedirect();

    expect($payment->fresh()->isReversed())->toBeTrue()
        ->and($this->accounts->summary($this->party->fresh())['salary_balance'])->toBe(12_000_000);
});

it('refuses to reverse the same salary payment twice', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);
    $payment = $run->payments->first();

    $this->actingAs($this->accountant)->post(route('party-payments.reverse', $payment), ['reason' => 'اول']);

    $this->actingAs($this->accountant)
        ->post(route('party-payments.reverse', $payment), ['reason' => 'دوباره'])
        ->assertSessionHasErrors('reason');
});

it('requires a reason to reverse a payment', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);
    $payment = $run->payments->first();

    $this->actingAs($this->accountant)
        ->post(route('party-payments.reverse', $payment), [])
        ->assertSessionHasErrors('reason');

    expect($payment->fresh()->isReversed())->toBeFalse();
});

it('forbids a warehouse user from reversing a salary payment', function () {
    $warehouse = User::factory()->create()->assignRole('warehouse');

    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
    ], $this->admin->id);
    $payment = $run->payments->first();

    $this->actingAs($warehouse)
        ->post(route('party-payments.reverse', $payment), ['reason' => 'تلاش غیرمجاز'])
        ->assertForbidden();

    expect($payment->fresh()->isReversed())->toBeFalse();
});

it('renders the payroll form\'s «پرداخت هم‌زمان» section with every required label', function () {
    $this->actingAs($this->admin)->get(route('payroll.create'))
        ->assertOk()
        ->assertSee('ثبت حقوق دوره')
        ->assertSee('پرداخت هم‌زمان')
        ->assertSee('مبلغ پرداخت')
        ->assertSee('حساب پرداخت‌کننده')
        ->assertSee('تاریخ پرداخت')
        ->assertSee('روش پرداخت')
        ->assertSee('شماره پیگیری')
        ->assertSee('توضیحات');
});

it('renders the employee page\'s standalone payment form with the required labels', function () {
    // The payment form only renders while «مانده حقوق» is positive — accrue first.
    $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    $this->actingAs($this->admin)->get(route('employees.show', $this->party))
        ->assertOk()
        ->assertSee('پرداخت حقوق')
        ->assertSee('حساب پرداخت‌کننده')
        ->assertSee('مبلغ پرداخت')
        ->assertSee('تاریخ پرداخت')
        ->assertSee('روش پرداخت')
        ->assertSee('شماره پیگیری')
        ->assertSee('سوابق پرداخت حقوق')
        ->assertSee('حقوق تحقق‌یافته')
        ->assertSee('حقوق پرداخت‌شده')
        ->assertSee('مانده حقوق');
});

/*
|--------------------------------------------------------------------------
| Linking a standalone payment to a specific run — and refusing a stranger's
|--------------------------------------------------------------------------
*/

it('links a standalone payment to the run it names, and refuses one that was never this employee\'s', function () {
    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000],
    ], $this->admin->id);

    $other = Party::createWithRole('employee', ['name' => 'رضا']);
    $otherEmployee = Employee::firstWhere('party_id', $other->id);
    $otherRun = $this->payroll->post('1405-04', [
        ['employee_id' => $otherEmployee->id, 'gross' => 8_000_000],
    ], $this->admin->id);

    expect(fn () => $this->payroll->paySalary($this->party, 5_000_000, $this->bank->id, forRun: $otherRun))
        ->toThrow(InvalidArgumentException::class);

    $payment = $this->payroll->paySalary($this->party, 5_000_000, $this->bank->id, forRun: $run);

    expect($payment->applied_id)->toBe($run->id);
});

/** Payment against another employee's balance is structurally impossible — each row's payment always uses that row's own party. */
it('never lets one employee\'s immediate payment touch another employee\'s balance', function () {
    $other = Party::createWithRole('employee', ['name' => 'رضا']);
    $otherEmployee = Employee::firstWhere('party_id', $other->id);

    $run = $this->payroll->post('1405-04', [
        ['employee_id' => $this->employee->id, 'gross' => 12_000_000, 'immediate_payment' => ($this->immediatePayment)()],
        ['employee_id' => $otherEmployee->id, 'gross' => 8_000_000],
    ], $this->admin->id);

    $payment = $run->payments->first();

    expect($payment->party_id)->toBe($this->party->id)
        ->and($this->accounts->summary($other->fresh())['salary_balance'])->toBe(8_000_000);
});
