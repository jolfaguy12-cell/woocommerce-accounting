<?php

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountTransaction;
use App\Domain\Accounting\Services\AccountTransactionService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\CounterAccountPolicy;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->service = app(AccountTransactionService::class);

    $this->attempt = fn (Account $counter) => $this->service->create([
        'bank_account_id' => $this->bank->id,
        'direction' => AccountTransaction::DIRECTION_OUT,
        'counter_account_id' => $counter->id,
        'purpose' => 'other',
        'amount' => 100_000,
        'transaction_date' => now(),
        'description' => 'تلاش برای دور زدن گردش کار',
        'created_by' => $this->admin->id,
    ]);
});

/**
 * The direct operation is the system's generic movement. If it could reach a
 * control account, it would be a back door around every workflow built on that
 * account — and the journal would move while the subsidiary ledger that owns the
 * balance never heard about it. These are the accounts it must never touch.
 */
dataset('control accounts', [
    'customer receivable' => [AccountCode::AccountsReceivable],
    'supplier payable' => [AccountCode::AccountsPayable],
    'customer credit' => [AccountCode::CustomerCredit],
    'supplier advance' => [AccountCode::SupplierAdvance],
    'employee advance' => [AccountCode::EmployeeAdvance],
    'payroll payable' => [AccountCode::PayrollPayable],
    'loan receivable' => [AccountCode::LoansReceivable],
    'loan payable' => [AccountCode::LoansPayable],
    'partner current account' => [AccountCode::PartnerCurrentAccount],
    'partner profit payable' => [AccountCode::PartnerProfitPayable],
    'capital' => [AccountCode::Capital],
    'partner withdrawal' => [AccountCode::PartnerWithdrawal],
    'inventory' => [AccountCode::Inventory],
    'cheques receivable' => [AccountCode::ChequesReceivable],
    'cheques payable' => [AccountCode::ChequesPayable],
    'retained earnings' => [AccountCode::RetainedEarnings],
]);

it('refuses a control account as the counter-account', function (AccountCode $code) {
    $account = $code->account();

    expect(fn () => ($this->attempt)($account))->toThrow(InvalidArgumentException::class);

    // Nothing was written: not the operation, not a journal line, not the balance.
    expect(AccountTransaction::count())->toBe(0)
        ->and($account->balance())->toBe(0)
        ->and($this->bank->account->balance())->toBe(0);
})->with('control accounts');

it('names the workflow that owns the account instead of just saying no', function () {
    // A refusal that does not say what to do instead gets worked around.
    expect(fn () => ($this->attempt)(AccountCode::AccountsPayable->account()))
        ->toThrow(InvalidArgumentException::class, 'پرداخت به تأمین‌کننده');

    expect(fn () => ($this->attempt)(AccountCode::PayrollPayable->account()))
        ->toThrow(InvalidArgumentException::class, 'لیست حقوق');
});

it('refuses an internal bank or cash account, pointing at the transfer operation', function () {
    $other = app(BankAccountManager::class)->create(['name' => 'صندوق', 'is_cash' => true]);

    expect(fn () => ($this->attempt)($other->account))
        ->toThrow(InvalidArgumentException::class, 'انتقال بین حساب‌ها');
});

it('refuses a parent account: a heading is not postable', function () {
    // The bank manager creates 1100-01 under 1100, which makes 1100 a heading.
    // Posting to it would double-count against the children beneath it.
    expect(fn () => ($this->attempt)(AccountCode::Bank->account()))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses an inactive account', function () {
    $account = AccountCode::OtherIncome->account();
    $account->update(['is_active' => false]);

    expect(fn () => ($this->attempt)($account))->toThrow(InvalidArgumentException::class);
});

it('refuses an account that is simply not on the allowlist', function () {
    // Sales revenue is neither a control account nor allowlisted: it is owned by
    // the order/profit engine. The gate is an allowlist, so it is refused by
    // default rather than needing to be remembered and blocked by hand.
    expect(fn () => ($this->attempt)(AccountCode::SalesRevenue->account()))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ($this->attempt)(AccountCode::Cogs->account()))
        ->toThrow(InvalidArgumentException::class);
});

it('allows exactly the eligible income and expense accounts, and posts each of them', function () {
    // The adjustment account (9999) is deliberately NOT here: it is allowlisted but
    // admin-only, and a policy asked without a user answers with the safest possible
    // list — so a caller that forgets to pass one gets fewer accounts, never more.
    $eligible = app(CounterAccountPolicy::class)->eligible()->pluck('code')->sort()->values()->all();

    expect($eligible)->toBe(['4900', '6000', '6350', '6370']);

    foreach ($eligible as $code) {
        $transaction = ($this->attempt)(Account::firstWhere('code', $code));
        expect($transaction->isPosted())->toBeTrue();
    }

    expect(AccountTransaction::count())->toBe(4);
});

it('never offers the form an account the service would refuse', function () {
    // The dropdown and the guard read the SAME policy, for the SAME user — a form that
    // offered a control account would be a bug report waiting to happen, and one that
    // offered an account the service then accepted would be the breach itself.
    $offered = $this->actingAs($this->admin)
        ->get('/financial-operations/create?type=withdrawal')
        ->assertOk()
        ->viewData('counterAccounts');

    expect($offered)->not->toBeEmpty();

    foreach ($offered as $account) {
        expect(CounterAccountPolicy::CONTROL_ACCOUNTS)->not->toHaveKey($account->code);
        expect(app(CounterAccountPolicy::class)->isEligible($account, $this->admin))->toBeTrue();
    }
});

it('cannot be bypassed by posting a control account id straight to the endpoint', function () {
    // The form is a convenience; the guard is the control. Skipping the UI and
    // POSTing the payable account's id must fail exactly the same way.
    $this->actingAs($this->admin)->post('/financial-operations', [
        'type' => 'withdrawal',
        'bank_account_id' => $this->bank->id,
        'counter_account_id' => AccountCode::AccountsPayable->account()->id,
        'purpose' => 'other',
        'amount' => 500_000,
        'transaction_date' => now()->toDateString(),
        'description' => 'تسویه تأمین‌کننده بدون ثبت پرداخت',
    ])->assertSessionHasErrors('amount');

    expect(AccountTransaction::count())->toBe(0)
        ->and(AccountCode::AccountsPayable->account()->balance())->toBe(0);
});
