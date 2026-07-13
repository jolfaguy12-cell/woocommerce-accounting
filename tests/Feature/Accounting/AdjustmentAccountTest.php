<?php

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\AccountTransaction;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Services\AccountTransactionService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\CounterAccountPolicy;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

/**
 * Account 9999 is the one counter-account that does not claim the money went anywhere:
 * it says "the books were wrong, and this makes them right". That is occasionally
 * necessary and always suspicious — it is the single line an operator would reach for to
 * make an unexplained difference disappear, and every difference it absorbs is a
 * reconciliation that will now never happen.
 *
 * So: admins only, a written reason, an external reference, and a second person's
 * approval — at any amount, whatever the approval threshold happens to be set to.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->secondAdmin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->service = app(AccountTransactionService::class);

    $this->adjustment = AccountCode::Rounding->account();

    // $overrides FIRST: `+` keeps the left-hand key, so the defaults must lose.
    $this->attempt = fn (User $actor, array $overrides = []) => $this->service->create($overrides + [
        'bank_account_id' => $this->bank->id,
        'direction' => AccountTransaction::DIRECTION_IN,
        'counter_account_id' => $this->adjustment->id,
        'purpose' => 'correction',
        'amount' => 12_000,
        'transaction_date' => now(),
        'description' => 'اختلاف رند کردن',
        'notes' => 'اختلاف ۱۲ هزار تومانی در صورت‌حساب بانک',
        'reference' => 'BK-4471',
        'created_by' => $actor->id,
    ]);
});

it('is never offered to an accountant, and is offered to an admin', function () {
    $policy = app(CounterAccountPolicy::class);

    expect($policy->eligible($this->accountant)->pluck('code'))->not->toContain('9999')
        ->and($policy->eligible($this->admin)->pluck('code'))->toContain('9999')
        // A policy asked with nobody in mind answers with the safest possible list.
        ->and($policy->eligible()->pluck('code'))->not->toContain('9999');
});

it('refuses an accountant at the SERVICE, not merely in the dropdown', function () {
    // Hiding it from the form is a courtesy. This is the control.
    expect(fn () => ($this->attempt)($this->accountant))->toThrow(InvalidArgumentException::class);

    expect(AccountTransaction::count())->toBe(0)
        ->and($this->adjustment->balance())->toBe(0);
});

it('cannot be reached by an accountant POSTing the account id straight to the endpoint', function () {
    $this->actingAs($this->accountant)->post('/financial-operations', [
        'type' => 'deposit',
        'bank_account_id' => $this->bank->id,
        'counter_account_id' => $this->adjustment->id,
        'purpose' => 'correction',
        'amount' => 12_000,
        'transaction_date' => now()->toDateString(),
        'description' => 'دور زدن فرم',
        'notes' => 'دلیل',
        'reference' => 'X-1',
    ])->assertSessionHasErrors('amount');

    expect(AccountTransaction::count())->toBe(0);
});

it('demands a written reason and an external reference', function () {
    // An adjustment that cannot say WHY, and point at something outside this system that
    // shows it, is indistinguishable from a plug.
    expect(fn () => ($this->attempt)($this->admin, ['notes' => null]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ($this->attempt)($this->admin, ['reference' => null]))
        ->toThrow(InvalidArgumentException::class);

    expect(AccountTransaction::count())->toBe(0);
});

it('always waits for a second person — even at 12,000 Toman with no threshold set', function () {
    // The amount is the wrong question. A 12,000 adjustment and a 12,000,000 one are the
    // same act: asserting the books were wrong. `ops.approval_threshold` is not even set
    // in this test, and it still cannot self-post.
    $transaction = ($this->attempt)($this->admin);

    expect($transaction->isPendingApproval())->toBeTrue()
        ->and($transaction->journal_entry_id)->toBeNull()
        ->and($this->adjustment->balance())->toBe(0);

    // The creator can never be their own approver.
    expect(fn () => $this->service->approve($transaction, $this->admin))
        ->toThrow(OperationStateException::class);

    $posted = $this->service->approve($transaction->fresh(), $this->secondAdmin);

    expect($posted->isPosted())->toBeTrue()
        ->and($this->adjustment->fresh()->balance())->toBe(-12_000); // credited: it offsets the deposit
});

it('will not let a non-admin approve an adjustment even if the approve role is widened', function () {
    Setting::set('ops.roles.approve', ['admin', 'accountant']);

    $transaction = ($this->attempt)($this->admin);

    // The accountant now holds the approve role — and still may not approve THIS.
    expect(fn () => $this->service->approve($transaction->fresh(), $this->accountant))
        ->toThrow(OperationStateException::class);

    expect($transaction->fresh()->journal_entry_id)->toBeNull();
});

it('leaves an ordinary income account alone: it posts immediately, no approval, no reference', function () {
    // The fence is around 9999, not around the whole feature.
    $transaction = ($this->attempt)($this->accountant, [
        'counter_account_id' => AccountCode::OtherIncome->account()->id,
        'purpose' => 'income',
        'notes' => null,
        'reference' => null,
    ]);

    expect($transaction->isPosted())->toBeTrue();
});
