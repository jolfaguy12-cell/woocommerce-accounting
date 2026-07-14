<?php

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyOffset;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Services\PartyOffsetService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PartyOffsetType;
use App\Models\User;
use App\Support\Design\TableQuery;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->service = app(PartyOffsetService::class);
    $this->ledger = app(PartyLedgerService::class);

    // One real person who is BOTH our customer and our supplier — the whole reason
    // offsets exist, and something the old single-role Party could not even express.
    $this->party = Party::createWithRole('customer', ['name' => 'شرکت دوسویه']);
    $this->party->activateRole('supplier');

    // Give them a balance on each account without going through four workflows.
    $this->seedBalance = function (AccountCode $code, string $side, int $amount) {
        $opposite = $side === 'debit' ? 'credit' : 'debit';

        app(JournalPoster::class)->post([
            'entry_date' => now(),
            'description' => 'مانده اولیه آزمون',
            'idempotency_key' => 'seed:'.uniqid(),
        ], [
            ['account' => $code, $side => $amount, 'party_id' => $this->party->id],
            ['account' => AccountCode::Capital, $opposite => $amount],
        ]);
    };

    $this->offset = fn (PartyOffsetType $type, int $amount) => $this->service->create([
        'party' => $this->party,
        'type' => $type,
        'amount' => $amount,
        'offset_date' => now(),
        'reason' => 'تهاتر توافقی',
        'created_by' => $this->admin->id,
    ]);
});

it('nets a customer receivable against a supplier payable for the same person', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 3_000_000);  // they owe us
    ($this->seedBalance)(AccountCode::AccountsPayable, 'credit', 2_000_000);    // we owe them

    $offset = ($this->offset)(PartyOffsetType::ReceivableAgainstPayable, 2_000_000);

    expect($offset->isPosted())->toBeTrue();

    // Both balances come down by the offset. No cash moved, and nothing was edited.
    expect($this->ledger->customerReceivable($this->party))->toBe(1_000_000)
        ->and($this->ledger->supplierPayable($this->party))->toBe(0);
});

it('consumes a customer credit against what the customer still owes', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 1_000_000);
    ($this->seedBalance)(AccountCode::CustomerCredit, 'credit', 400_000);

    ($this->offset)(PartyOffsetType::CreditAgainstReceivable, 400_000);

    expect($this->ledger->customerReceivable($this->party))->toBe(600_000)
        ->and($this->ledger->customerCredit($this->party))->toBe(0);
});

it('consumes a supplier advance against the invoice that finally arrived', function () {
    ($this->seedBalance)(AccountCode::SupplierAdvance, 'debit', 500_000);
    ($this->seedBalance)(AccountCode::AccountsPayable, 'credit', 800_000);

    ($this->offset)(PartyOffsetType::AdvanceAgainstPayable, 500_000);

    expect($this->ledger->balanceOn($this->party, AccountCode::SupplierAdvance))->toBe(0)
        ->and($this->ledger->supplierPayable($this->party))->toBe(300_000);
});

it('refuses to offset more than the smaller of the two balances', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 3_000_000);
    ($this->seedBalance)(AccountCode::AccountsPayable, 'credit', 500_000);

    // Offsetting 3m against a 500k payable does not settle a debt — it invents one
    // in the opposite direction and leaves them owing us money they never owed.
    expect(fn () => ($this->offset)(PartyOffsetType::ReceivableAgainstPayable, 3_000_000))
        ->toThrow(InvalidArgumentException::class);

    expect(PartyOffset::count())->toBe(0)
        ->and($this->ledger->supplierPayable($this->party))->toBe(500_000);

    // The cap is exactly the smaller side.
    expect($this->service->cap($this->party, PartyOffsetType::ReceivableAgainstPayable))->toBe(500_000);
});

it('refuses an offset when one side has no balance at all', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 3_000_000);
    // ...but nothing on the payable.

    expect(fn () => ($this->offset)(PartyOffsetType::ReceivableAgainstPayable, 100_000))
        ->toThrow(InvalidArgumentException::class);

    expect(PartyOffset::count())->toBe(0);
});

it('puts the same party on BOTH legs, so an offset can never move one persons debt onto another', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 1_000_000);
    ($this->seedBalance)(AccountCode::AccountsPayable, 'credit', 1_000_000);

    $offset = ($this->offset)(PartyOffsetType::ReceivableAgainstPayable, 1_000_000);
    $lines = $offset->journalEntry->lines;

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('party_id')->unique()->all())->toBe([$this->party->id])
        ->and($lines->sum('debit'))->toBe($lines->sum('credit'));
});

it('moves no cash', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 1_000_000);
    ($this->seedBalance)(AccountCode::AccountsPayable, 'credit', 1_000_000);

    $offset = ($this->offset)(PartyOffsetType::ReceivableAgainstPayable, 1_000_000);

    $cashAndBank = $offset->journalEntry->lines->load('account')
        ->filter(fn (JournalLine $l) => in_array($l->account->code, ['1000', '1100'], true));

    expect($cashAndBank)->toBeEmpty();
});

it('reverses cleanly, restoring both balances and leaving the original entry posted', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 3_000_000);
    ($this->seedBalance)(AccountCode::AccountsPayable, 'credit', 2_000_000);

    $offset = ($this->offset)(PartyOffsetType::ReceivableAgainstPayable, 2_000_000);
    $original = $offset->journalEntry;

    $this->service->reverse($offset, 'توافق تهاتر لغو شد', $this->admin);

    expect($offset->fresh()->isReversed())->toBeTrue()
        ->and($original->fresh()->status)->toBe('reversed')
        ->and($original->fresh()->lines->sum('debit'))->toBe(2_000_000) // untouched
        ->and($this->ledger->customerReceivable($this->party))->toBe(3_000_000)
        ->and($this->ledger->supplierPayable($this->party))->toBe(2_000_000);
});

it('shows both legs in the unified party statement', function () {
    ($this->seedBalance)(AccountCode::AccountsReceivable, 'debit', 1_000_000);
    ($this->seedBalance)(AccountCode::AccountsPayable, 'credit', 1_000_000);

    ($this->offset)(PartyOffsetType::ReceivableAgainstPayable, 1_000_000);

    $statement = $this->ledger->statement($this->party, new TableQuery(request: Request::create('/')));
    $offsetLines = $statement->getCollection()->filter(fn ($l) => str_contains((string) $l->memo, 'تهاتر'));

    // Not one leg — both. A statement that showed only the receivable side would
    // make the payable look like it vanished on its own.
    expect($offsetLines)->toHaveCount(2)
        ->and($offsetLines->pluck('account.code')->sort()->values()->all())->toBe(['1200', '2000']);
});
