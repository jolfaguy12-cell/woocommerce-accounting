<?php

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\AccountTransaction;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Services\AccountTransactionService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\CounterAccountPolicy;
use App\Domain\Accounting\Support\OperationPolicy;
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
 * So it is fenced: admins only, and never without a written reason and an external
 * reference. It is NOT fenced with a mandatory second approver — see canApprove.
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

it('posts straight away for an admin — no second signature required', function () {
    // There is no mandatory four-eyes rule here, on an adjustment or on anything else:
    // this is frequently a one-bookkeeper business, and a control that needs a second
    // human being to exist does not protect the books when there is no second human —
    // it just pushes the work outside the system. What fences 9999 is that only an
    // admin can reach it, and only with a reason and a reference.
    $transaction = ($this->attempt)($this->admin);

    expect($transaction->isPosted())->toBeTrue()
        ->and($transaction->created_by)->toBe($this->admin->id)   // still attributable
        ->and($this->adjustment->fresh()->balance())->toBe(-12_000); // credited: it offsets the deposit
});

it('still parks an adjustment when a threshold is set, and only an admin may approve it', function () {
    Setting::set(OperationPolicy::APPROVAL_THRESHOLD, 10_000);
    Setting::set('ops.roles.approve', ['admin', 'accountant']);

    $transaction = ($this->attempt)($this->admin);

    expect($transaction->isPendingApproval())->toBeTrue()
        ->and($transaction->journal_entry_id)->toBeNull();

    // The accountant now holds the approve role — and still may not approve THIS one.
    expect(fn () => $this->service->approve($transaction->fresh(), $this->accountant))
        ->toThrow(OperationStateException::class);

    // The admin who created it may approve it themselves. The threshold is a
    // "look again" prompt, not a two-person rule.
    $posted = $this->service->approve($transaction->fresh(), $this->admin);

    expect($posted->isPosted())->toBeTrue();
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
