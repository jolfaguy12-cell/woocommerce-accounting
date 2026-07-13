<?php

use App\Domain\Accounting\Models\AccountTransaction;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\AccountTransactionService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت', 'bank_name' => 'ملت']);
    $this->other = app(BankAccountManager::class)->create(['name' => 'صندوق', 'is_cash' => true]);

    $this->service = app(AccountTransactionService::class);

    $this->record = fn (array $overrides = []) => $this->service->create(array_merge([
        'bank_account_id' => $this->bank->id,
        'direction' => AccountTransaction::DIRECTION_IN,
        'counter_account_id' => AccountCode::OtherIncome->account()->id,
        'purpose' => 'income',
        'amount' => 300_000,
        'transaction_date' => now(),
        'description' => 'اجاره ویترین فروشگاه',
        'created_by' => $this->admin->id,
    ], $overrides));
});

it('records income, which nothing in the system could do before', function () {
    // Account 4900 had no writer at all: money genuinely earned had nowhere to go.
    expect(AccountCode::OtherIncome->account()->balance())->toBe(0);

    $transaction = ($this->record)();
    $entry = $transaction->journalEntry->load('lines.account');

    expect($transaction->isPosted())->toBeTrue()
        ->and($entry->lines)->toHaveCount(2)
        ->and($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'));

    // Bank up, revenue recognised. (Revenue is a credit balance, so its debit-minus-credit is negative.)
    expect($this->bank->account->balance())->toBe(300_000)
        ->and(AccountCode::OtherIncome->account()->balance())->toBe(-300_000);
});

it('records a withdrawal against its counter-account', function () {
    ($this->record)(); // fund the bank first

    $transaction = ($this->record)([
        'direction' => AccountTransaction::DIRECTION_OUT,
        'counter_account_id' => AccountCode::BankFee->account()->id,
        'purpose' => 'bank_fee',
        'amount' => 50_000,
        'description' => 'کارمزد ماهانه بانک',
    ]);

    expect($transaction->isDeposit())->toBeFalse()
        ->and($this->bank->account->balance())->toBe(250_000)
        ->and(AccountCode::BankFee->account()->balance())->toBe(50_000);
});

it('cannot express a balance-only adjustment: the counter-account is mandatory', function () {
    // Not merely a form rule — the column is NOT NULL, so an operation that moves a
    // balance without saying where the other side went cannot even be stored.
    expect(fn () => $this->service->create([
        'bank_account_id' => $this->bank->id,
        'direction' => AccountTransaction::DIRECTION_IN,
        'counter_account_id' => null,
        'purpose' => 'other',
        'amount' => 100_000,
        'transaction_date' => now(),
        'description' => 'بدون طرف مقابل',
    ]))->toThrow(ModelNotFoundException::class);

    expect(AccountTransaction::count())->toBe(0);
});

it('refuses its own account as the counter-account', function () {
    expect(fn () => ($this->record)(['counter_account_id' => $this->bank->account_id]))
        ->toThrow(InvalidArgumentException::class);

    expect(AccountTransaction::count())->toBe(0);
});

it('sends an account-to-account movement to the transfer operation instead', function () {
    // Using another bank account as the counter-account would post a technically
    // valid entry — and would be invisible to every transfer report, and could
    // carry no bank fee. So it is refused here and pointed at the right operation.
    expect(fn () => ($this->record)(['counter_account_id' => $this->other->account_id]))
        ->toThrow(InvalidArgumentException::class);

    expect(AccountTransaction::count())->toBe(0);
});

it('puts the party on the counter line, so the money is traceable to whoever it came from', function () {
    $party = Party::create(['type' => 'customer', 'name' => 'مستأجر ویترین']);

    $transaction = ($this->record)(['party_id' => $party->id]);
    $lines = $transaction->journalEntry->lines;

    $counterLine = $lines->firstWhere('account_id', AccountCode::OtherIncome->account()->id);
    $bankLine = $lines->firstWhere('account_id', $this->bank->account_id);

    expect($counterLine->party_id)->toBe($party->id)
        ->and($bankLine->party_id)->toBeNull(); // our own account has no counterparty
});

it('reverses without touching the original entry', function () {
    $transaction = ($this->record)();
    $original = $transaction->journalEntry;

    $this->service->reverse($transaction, 'دوباره ثبت شده بود', $this->admin);

    expect($transaction->fresh()->isReversed())->toBeTrue()
        ->and($original->fresh()->status)->toBe('reversed')
        ->and($original->fresh()->lines->sum('debit'))->toBe(300_000)  // untouched
        ->and($this->bank->account->balance())->toBe(0)
        ->and(AccountCode::OtherIncome->account()->balance())->toBe(0);
});

it('shows a direct transaction in the bank-account ledger', function () {
    ($this->record)();

    $this->actingAs($this->admin)->get("/bank-accounts/{$this->bank->id}")
        ->assertOk()
        ->assertSee('واریز مستقیم: اجاره ویترین فروشگاه');
});
