<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Support\Design\TableQuery;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->ledger = app(PartyLedgerService::class);
    $this->party = Party::createWithRole('customer', ['name' => 'شرکت چندنقشه']);
    $this->party->activateRole('supplier');
    $this->party->activateRole('partner');
});

function post(Party $party, AccountCode $debit, AccountCode $credit, int $amount, string $key, string $date = '2026-07-01'): void
{
    app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse($date, 'Asia/Tehran'),
        'description' => "سند {$key}",
        'idempotency_key' => $key,
    ], [
        ['account' => $debit, 'debit' => $amount, 'party_id' => $party->id],
        ['account' => $credit, 'credit' => $amount, 'party_id' => $party->id],
    ]);
}

it('keeps a receivable, a payable and a loan on one party completely separate', function () {
    post($this->party, AccountCode::AccountsReceivable, AccountCode::SalesRevenue, 500_000, 'ar');
    post($this->party, AccountCode::Inventory, AccountCode::AccountsPayable, 300_000, 'ap');
    post($this->party, AccountCode::Bank, AccountCode::LoansPayable, 1_000_000, 'loan');

    expect($this->ledger->customerReceivable($this->party))->toBe(500_000)
        ->and($this->ledger->supplierPayable($this->party))->toBe(300_000)
        ->and($this->ledger->loanPayable($this->party))->toBe(1_000_000)
        ->and($this->ledger->customerCredit($this->party))->toBe(0);

    // Paying the supplier must not touch the receivable or the loan.
    post($this->party, AccountCode::AccountsPayable, AccountCode::Bank, 300_000, 'pay-ap');

    expect($this->ledger->supplierPayable($this->party))->toBe(0)
        ->and($this->ledger->customerReceivable($this->party))->toBe(500_000)
        ->and($this->ledger->loanPayable($this->party))->toBe(1_000_000);
});

it('reports each balance in the direction its account naturally runs', function () {
    post($this->party, AccountCode::Bank, AccountCode::CustomerCredit, 200_000, 'credit');
    post($this->party, AccountCode::LoansReceivable, AccountCode::Bank, 750_000, 'lent');
    post($this->party, AccountCode::Bank, AccountCode::PartnerCurrentAccount, 400_000, 'partner');

    // Liabilities read positive when we owe; assets read positive when owed to us.
    expect($this->ledger->customerCredit($this->party))->toBe(200_000)
        ->and($this->ledger->loanReceivable($this->party))->toBe(750_000)
        ->and($this->ledger->partnerCurrentAccount($this->party))->toBe(400_000);
});

it('reports an overpaid supplier as an advance rather than a negative payable', function () {
    post($this->party, AccountCode::Inventory, AccountCode::AccountsPayable, 100_000, 'invoice');
    post($this->party, AccountCode::AccountsPayable, AccountCode::Bank, 250_000, 'overpay');

    // Nothing posts to 1450 yet — PaymentRecorder still drives AP negative — so
    // the advance is the negative part of the payable, surfaced not hidden.
    expect($this->ledger->supplierPayable($this->party))->toBe(-150_000)
        ->and($this->ledger->supplierAdvance($this->party))->toBe(150_000)
        ->and($this->ledger->balances($this->party))->not->toHaveKey('supplier_payable')
        ->and($this->ledger->balances($this->party)['supplier_advance']['amount'])->toBe(150_000);
});

it('nets the consolidated position for display without touching a single balance', function () {
    post($this->party, AccountCode::AccountsReceivable, AccountCode::SalesRevenue, 500_000, 'ar');
    post($this->party, AccountCode::Inventory, AccountCode::AccountsPayable, 300_000, 'ap');

    $entriesBefore = JournalEntry::count();
    $linesBefore = JournalLine::count();

    // 500k owed to us − 300k we owe = +200k, in our favour.
    expect($this->ledger->consolidatedPosition($this->party))->toBe(200_000)
        // …and the underlying balances are untouched: nothing was offset.
        ->and($this->ledger->customerReceivable($this->party))->toBe(500_000)
        ->and($this->ledger->supplierPayable($this->party))->toBe(300_000)
        ->and(JournalEntry::count())->toBe($entriesBefore)
        ->and(JournalLine::count())->toBe($linesBefore);
});

it('returns every line across every account in the complete statement', function () {
    post($this->party, AccountCode::AccountsReceivable, AccountCode::SalesRevenue, 500_000, 'ar', '2026-07-01');
    post($this->party, AccountCode::Inventory, AccountCode::AccountsPayable, 300_000, 'ap', '2026-07-02');

    $other = Party::createWithRole('customer', ['name' => 'دیگری']);
    post($other, AccountCode::AccountsReceivable, AccountCode::SalesRevenue, 900_000, 'other-ar');

    $statement = $this->ledger->statement($this->party, new TableQuery(request: Request::create('/')));

    $expectedCodes = collect([
        AccountCode::AccountsReceivable->value,
        AccountCode::SalesRevenue->value,
        AccountCode::Inventory->value,
        AccountCode::AccountsPayable->value,
    ])->sort()->values()->all();

    // Both lines of each of this party's two entries (4), and none of the other party's.
    expect($statement->total())->toBe(4)
        ->and($statement->getCollection()->pluck('party_id')->unique()->all())->toBe([$this->party->id])
        ->and($statement->getCollection()->pluck('account.code')->sort()->values()->all())->toBe($expectedCodes);
});

it('excludes a party with no journal activity from every balance card', function () {
    $fresh = Party::createWithRole('customer', ['name' => 'بدون گردش']);

    expect($this->ledger->balances($fresh))->toBe([])
        ->and($this->ledger->consolidatedPosition($fresh))->toBe(0);
});
