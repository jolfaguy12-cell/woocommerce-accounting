<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Expenses\Services\BankAccountManager;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->supplier = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);
    $this->ledger = app(PartyLedgerService::class);

    /**
     * Reproduce the OLD behaviour by hand: an overpayment posted straight against
     * 2000, driving it negative. PaymentRecorder no longer does this, so the only
     * way to create the legacy shape the command exists to fix is to post it
     * directly — which is exactly what production's history looks like.
     */
    $this->legacyOverpayment = function (Party $party, int $amount) {
        app(JournalPoster::class)->post([
            'entry_date' => now(),
            'description' => 'پرداخت مازاد (روش قدیمی)',
            'idempotency_key' => 'legacy:'.uniqid(),
        ], [
            ['account' => AccountCode::AccountsPayable, 'debit' => $amount, 'party_id' => $party->id],
            ['account' => $this->bank->account_id, 'credit' => $amount],
        ]);
    };
});

it('moves a historical negative payable onto the supplier-advance account', function () {
    ($this->legacyOverpayment)($this->supplier, 500_000);

    expect($this->ledger->supplierPayable($this->supplier))->toBe(-500_000)
        ->and($this->ledger->balanceOn($this->supplier, AccountCode::SupplierAdvance))->toBe(0);

    $this->artisan('suppliers:reclass-advances')->assertSuccessful();

    // The payable is flat and the prepayment is where it belongs — as an ASSET.
    expect($this->ledger->supplierPayable($this->supplier))->toBe(0)
        ->and($this->ledger->balanceOn($this->supplier, AccountCode::SupplierAdvance))->toBe(500_000);
});

it('posts the correction rather than editing history', function () {
    ($this->legacyOverpayment)($this->supplier, 500_000);
    $originalEntry = JournalEntry::latest('id')->first();
    $originalLines = $originalEntry->lines->map->only(['account_id', 'debit', 'credit'])->toArray();

    $this->artisan('suppliers:reclass-advances')->assertSuccessful();

    // The original entry is byte-for-byte what it was. The fix sits on top of it,
    // as a new entry that can be seen, explained and reversed — which is the whole
    // difference between an accounting correction and quietly rewriting the books.
    $originalEntry->refresh()->load('lines');

    expect($originalEntry->status)->toBe('posted')
        ->and($originalEntry->lines->map->only(['account_id', 'debit', 'credit'])->toArray())->toBe($originalLines)
        ->and(JournalEntry::where('idempotency_key', "supplier_advance_reclass:{$this->supplier->id}")->exists())->toBeTrue();
});

it('leaves the supplier net position unchanged — it reclassifies, it does not adjust', function () {
    ($this->legacyOverpayment)($this->supplier, 500_000);

    $before = $this->ledger->consolidatedPosition($this->supplier);

    $this->artisan('suppliers:reclass-advances')->assertSuccessful();

    // Assets rose by 500k and liabilities rose by 500k. What we are owed, net, is
    // identical — if this number moved, the command would be changing the books,
    // not reclassifying them.
    expect($this->ledger->consolidatedPosition($this->supplier))->toBe($before);
});

it('is idempotent: running it twice posts nothing the second time', function () {
    ($this->legacyOverpayment)($this->supplier, 500_000);

    $this->artisan('suppliers:reclass-advances')->assertSuccessful();
    $entriesAfterFirst = JournalEntry::count();

    $this->artisan('suppliers:reclass-advances')->assertSuccessful();

    expect(JournalEntry::count())->toBe($entriesAfterFirst)
        ->and($this->ledger->balanceOn($this->supplier, AccountCode::SupplierAdvance))->toBe(500_000);
});

it('leaves a normal positive payable completely alone', function () {
    app(JournalPoster::class)->post([
        'entry_date' => now(),
        'description' => 'فاکتور خرید',
        'idempotency_key' => 'invoice:1',
    ], [
        ['account' => AccountCode::Inventory, 'debit' => 800_000],
        ['account' => AccountCode::AccountsPayable, 'credit' => 800_000, 'party_id' => $this->supplier->id],
    ]);

    $entriesBefore = JournalEntry::count();

    $this->artisan('suppliers:reclass-advances')->assertSuccessful();

    expect(JournalEntry::count())->toBe($entriesBefore)
        ->and($this->ledger->supplierPayable($this->supplier))->toBe(800_000);
});

it('changes nothing on a dry run', function () {
    ($this->legacyOverpayment)($this->supplier, 500_000);
    $entriesBefore = JournalEntry::count();

    $this->artisan('suppliers:reclass-advances', ['--dry-run' => true])->assertSuccessful();

    expect(JournalEntry::count())->toBe($entriesBefore)
        ->and($this->ledger->supplierPayable($this->supplier))->toBe(-500_000);
});

it('keeps the whole ledger balanced', function () {
    $other = Party::create(['type' => 'supplier', 'name' => 'پخش شیراز']);
    ($this->legacyOverpayment)($this->supplier, 500_000);
    ($this->legacyOverpayment)($other, 250_000);

    $this->artisan('suppliers:reclass-advances')->assertSuccessful();

    $sums = JournalLine::selectRaw('SUM(debit) as d, SUM(credit) as c')->first();

    expect((int) $sums->d - (int) $sums->c)->toBe(0)
        ->and($this->ledger->balanceOn($other, AccountCode::SupplierAdvance))->toBe(250_000);
});
