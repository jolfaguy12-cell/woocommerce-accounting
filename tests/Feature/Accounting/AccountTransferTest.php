<?php

use App\Domain\Accounting\Exceptions\NegativeBalanceException;
use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Exceptions\PeriodLockedException;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\AccountTransfer;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Services\AccountTransferService;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');

    $this->source = app(BankAccountManager::class)->create(['name' => 'صندوق', 'is_cash' => true]);
    $this->destination = app(BankAccountManager::class)->create(['name' => 'بانک ملت', 'bank_name' => 'ملت']);

    $this->service = app(AccountTransferService::class);

    // Seed the source so transfers out of it are not overdrafts.
    $this->fund = function (int $amount) {
        app(JournalPoster::class)->post([
            'entry_date' => now(),
            'description' => 'موجودی اولیه',
            'idempotency_key' => 'seed:'.uniqid(),
        ], [
            ['account' => $this->source->account_id, 'debit' => $amount],
            ['account' => AccountCode::Capital, 'credit' => $amount],
        ]);
    };

    $this->transfer = fn (array $overrides = []) => $this->service->create(array_merge([
        'from_bank_account_id' => $this->source->id,
        'to_bank_account_id' => $this->destination->id,
        'amount' => 1_000_000,
        'transfer_date' => now(),
        'created_by' => $this->admin->id,
    ], $overrides));
});

it('posts one balanced entry that moves money without creating income or expense', function () {
    ($this->fund)(5_000_000);

    $transfer = ($this->transfer)();

    expect($transfer->isPosted())->toBeTrue()
        ->and($transfer->journal_entry_id)->not->toBeNull();

    $entry = $transfer->journalEntry->load('lines.account');

    // One entry, two lines: credit source, debit destination. Balanced.
    expect($entry->lines)->toHaveCount(2)
        ->and($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'))
        ->and($entry->lines->sum('debit'))->toBe(1_000_000);

    // The move itself touches only asset accounts — the business is neither richer
    // nor poorer for having shuffled its own money.
    expect($entry->lines->pluck('account.type')->unique()->all())->toBe(['asset']);

    expect($this->source->account->balance())->toBe(4_000_000)
        ->and($this->destination->account->balance())->toBe(1_000_000);
});

it('shows the transfer in BOTH bank-account ledgers, each reading in its own direction', function () {
    ($this->fund)(5_000_000);
    $transfer = ($this->transfer)();

    $sourceLine = JournalLine::where('account_id', $this->source->account_id)
        ->where('journal_entry_id', $transfer->journal_entry_id)->sole();
    $destinationLine = JournalLine::where('account_id', $this->destination->account_id)
        ->where('journal_entry_id', $transfer->journal_entry_id)->sole();

    // Same entry, opposite sides — money out of one is money into the other.
    expect($sourceLine->credit)->toBe(1_000_000)
        ->and($sourceLine->debit)->toBe(0)
        ->and($destinationLine->debit)->toBe(1_000_000)
        ->and($destinationLine->credit)->toBe(0);

    // Each side's memo names the OTHER account, so neither ledger shows a reader
    // a sentence written from the other account's point of view.
    expect($sourceLine->memo)->toBe('انتقال به بانک ملت')
        ->and($destinationLine->memo)->toBe('انتقال از صندوق');

    // And both ledger pages actually render it.
    $this->actingAs($this->admin)->get("/bank-accounts/{$this->source->id}")
        ->assertOk()->assertSee('انتقال به بانک ملت');
    $this->actingAs($this->admin)->get("/bank-accounts/{$this->destination->id}")
        ->assertOk()->assertSee('انتقال از صندوق');
});

it('posts the bank fee as a real expense on 6350, separate from the transferred amount', function () {
    ($this->fund)(5_000_000);

    $transfer = ($this->transfer)(['bank_fee' => 25_000]);
    $entry = $transfer->journalEntry->load('lines.account');

    $fee = AccountCode::BankFee->account();
    $feeLine = $entry->lines->firstWhere('account_id', $fee->id);

    expect($feeLine->debit)->toBe(25_000)
        ->and($entry->lines->sum('debit'))->toBe($entry->lines->sum('credit'));

    // The destination receives exactly the transferred amount; the fee comes out of
    // the source on top of it, so it can never be silently financed by the recipient.
    expect($this->destination->account->balance())->toBe(1_000_000)
        ->and($this->source->account->balance())->toBe(5_000_000 - 1_000_000 - 25_000)
        ->and($transfer->totalOutflow())->toBe(1_025_000);
});

it('refuses a transfer to the same account', function () {
    ($this->fund)(5_000_000);

    expect(fn () => ($this->transfer)(['to_bank_account_id' => $this->source->id]))
        ->toThrow(InvalidArgumentException::class);

    expect(AccountTransfer::count())->toBe(0);
});

it('refuses a transfer involving an inactive account', function () {
    ($this->fund)(5_000_000);
    $this->destination->update(['is_active' => false]);

    expect(fn () => ($this->transfer)())->toThrow(InvalidArgumentException::class);
    expect(AccountTransfer::count())->toBe(0);
});

it('reverses both sides and the fee together, leaving the original entry intact', function () {
    ($this->fund)(5_000_000);
    $transfer = ($this->transfer)(['bank_fee' => 25_000]);
    $original = $transfer->journalEntry;

    $reversed = $this->service->reverse($transfer, 'اشتباه در حساب مقصد', $this->admin);

    expect($reversed->isReversed())->toBeTrue()
        ->and($reversed->reversal_reason)->toBe('اشتباه در حساب مقصد')
        ->and($reversed->reversed_by)->toBe($this->admin->id);

    // The original is flagged, never edited: its lines are byte-for-byte what they were.
    $original->refresh()->load('lines');
    expect($original->status)->toBe('reversed')
        ->and($original->lines->sum('debit'))->toBe(1_025_000);

    // Every balance is back where it started — including the fee expense.
    expect($this->source->account->balance())->toBe(5_000_000)
        ->and($this->destination->account->balance())->toBe(0)
        ->and(AccountCode::BankFee->account()->balance())->toBe(0);
});

it('is idempotent: re-posting the same transfer re-attaches the one entry it already has', function () {
    ($this->fund)(5_000_000);
    $transfer = ($this->transfer)();
    $entryCount = JournalEntry::count();

    // Posting an already-posted operation is refused outright...
    expect(fn () => $this->service->post($transfer, $this->admin->id))
        ->toThrow(OperationStateException::class);

    // ...and even if the status were forced back, the idempotency key would return
    // the SAME entry rather than double-spending the source account.
    $transfer->forceFill(['status' => 'draft'])->save();
    $again = $this->service->post($transfer->fresh(), $this->admin->id);

    expect($again->journal_entry_id)->toBe($transfer->journal_entry_id)
        ->and(JournalEntry::count())->toBe($entryCount)
        ->and($this->source->account->balance())->toBe(4_000_000);
});

it('refuses to post into a locked period', function () {
    ($this->fund)(5_000_000);

    AccountingPeriod::forDate(now())->update(['status' => 'locked']);

    expect(fn () => ($this->transfer)())->toThrow(PeriodLockedException::class);
});

it('blocks an overdraft when ops.negative_balance_mode is block, and allows it otherwise', function () {
    // Source has nothing in it.
    Setting::set(OperationPolicy::NEGATIVE_BALANCE_MODE, OperationPolicy::MODE_BLOCK);

    expect(fn () => ($this->transfer)())->toThrow(NegativeBalanceException::class);
    expect(AccountTransfer::where('status', 'posted')->count())->toBe(0);

    Setting::set(OperationPolicy::NEGATIVE_BALANCE_MODE, OperationPolicy::MODE_WARN);

    $transfer = ($this->transfer)();

    expect($transfer->isPosted())->toBeTrue()
        ->and($this->source->account->balance())->toBe(-1_000_000);
});

it('freezes the financial substance of a posted transfer', function () {
    ($this->fund)(5_000_000);
    $transfer = ($this->transfer)();

    expect(fn () => $transfer->update(['amount' => 9_999]))
        ->toThrow(OperationStateException::class);

    expect($transfer->fresh()->amount)->toBe(1_000_000);
});

it('records the entry on the transfer date, not today', function () {
    ($this->fund)(5_000_000);

    $transfer = ($this->transfer)(['transfer_date' => now()->subDays(3)]);

    expect($transfer->journalEntry->entry_date->toDateString())
        ->toBe(now()->subDays(3)->toDateString())
        ->and($transfer->jalali_period)
        ->toBe(JalaliPeriod::fromDate(now()->subDays(3)));
});
