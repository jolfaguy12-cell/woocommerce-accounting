<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyAlias;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Services\PartyMergeService;
use App\Domain\Accounting\Support\AccountCode;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');

    $this->merges = app(PartyMergeService::class);
    $this->ledger = app(PartyLedgerService::class);

    $this->survivor = Party::createWithRole('customer', ['name' => 'مریم احمدی', 'phone' => '09121112233']);
    $this->duplicate = Party::createWithRole('customer', ['name' => 'مریم احمدی', 'phone' => '09121112233']);

    // A receivable posted against the DUPLICATE, before anyone noticed it was one.
    $this->invoice = fn (Party $party, int $amount) => app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'description' => 'فروش',
        'idempotency_key' => 'test:'.$party->id.':'.$amount,
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => $amount, 'party_id' => $party->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => $amount],
    ]);
});

/**
 * The invariant the whole design exists to protect. A merge must never touch a
 * posted line: the entries were posted against the id that existed at the time,
 * they reconcile against it, and an audit must find what was actually posted.
 */
it('never rewrites a single journal line', function () {
    ($this->invoice)($this->duplicate, 400_000);
    ($this->invoice)($this->survivor, 600_000);

    $before = JournalLine::orderBy('id')->get(['id', 'party_id', 'account_id', 'debit', 'credit'])->toArray();

    $this->merges->merge($this->survivor, $this->duplicate, 'یک نفر، دو بار ثبت شده', $this->admin);

    expect(JournalLine::orderBy('id')->get(['id', 'party_id', 'account_id', 'debit', 'credit'])->toArray())
        ->toBe($before);
});

/**
 * …and yet the survivor's balance must be the whole story. That is only possible
 * because balances sum over identityIds(), not over one party_id.
 */
it('aggregates the absorbed party\'s balance into the survivor', function () {
    ($this->invoice)($this->duplicate, 400_000);
    ($this->invoice)($this->survivor, 600_000);

    expect($this->ledger->customerReceivable($this->survivor))->toBe(600_000);

    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    expect($this->survivor->fresh()->identityIds())
        ->toEqualCanonicalizing([$this->survivor->id, $this->duplicate->id])
        ->and($this->ledger->customerReceivable($this->survivor->fresh()))->toBe(1_000_000);
});

it('records an auditable alias with the reason, the actor and a snapshot', function () {
    $this->merges->merge($this->survivor, $this->duplicate, 'همان شخص است', $this->admin);

    $alias = PartyAlias::firstWhere('merged_party_id', $this->duplicate->id);

    expect($alias->party_id)->toBe($this->survivor->id)
        ->and($alias->reason)->toBe('همان شخص است')
        ->and($alias->merged_by)->toBe($this->admin->id)
        ->and($alias->snapshot['name'])->toBe('مریم احمدی')
        ->and($alias->snapshot['phone'])->toBe('09121112233');
});

it('moves operational records but leaves the absorbed party in place', function () {
    // Live business records follow the identity; posted history does not.
    $account = $this->duplicate->bankAccounts()->create(['bank_name' => 'ملت', 'account_number' => '123']);
    $login = User::factory()->create(['party_id' => $this->duplicate->id]);

    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    expect($account->fresh()->party_id)->toBe($this->survivor->id)
        ->and($login->fresh()->party_id)->toBe($this->survivor->id)
        // The absorbed party is NOT deleted and its id is NOT reused — the ledger
        // still points at it.
        ->and(Party::find($this->duplicate->id))->not->toBeNull()
        ->and($this->duplicate->fresh()->merged_into_id)->toBe($this->survivor->id);
});

it('unions the roles, so a customer merged into a supplier is now both', function () {
    $supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);

    $this->merges->merge($supplier, $this->duplicate, 'ادغام', $this->admin);

    $supplier->refresh();

    expect($supplier->hasRole('supplier'))->toBeTrue()
        ->and($supplier->hasRole('customer'))->toBeTrue()
        ->and($supplier->customerProfile)->not->toBeNull(); // activateRole created the profile
});

it('drops the absorbed party out of every list and out of the picker', function () {
    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    expect(Party::notMerged()->pluck('id')->all())->not->toContain($this->duplicate->id);

    $this->actingAs($this->admin)
        ->getJson(route('parties.search', ['q' => 'مریم']))
        ->assertOk()
        ->assertJsonMissing(['id' => $this->duplicate->id])
        ->assertJsonFragment(['id' => $this->survivor->id]);
});

it('redirects the absorbed party\'s profile to the surviving identity', function () {
    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    $this->actingAs($this->admin)
        ->get(route('parties.show', $this->duplicate))
        ->assertRedirect(route('parties.show', $this->survivor));
});

it('refuses to merge a party with itself, or one that is already merged', function () {
    expect(fn () => $this->merges->merge($this->survivor, $this->survivor, 'خطا', $this->admin))
        ->toThrow(InvalidArgumentException::class);

    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    $third = Party::createWithRole('customer', ['name' => 'سومی']);

    expect(fn () => $this->merges->merge($third, $this->duplicate->fresh(), 'دوباره', $this->admin))
        ->toThrow(InvalidArgumentException::class);
});

it('is admin-only and always demands a reason', function () {
    $this->actingAs($this->accountant)
        ->post(route('parties.merge', $this->survivor), [
            'merged_party_id' => $this->duplicate->id,
            'reason' => 'تلاش حسابدار',
        ])->assertForbidden();

    $this->actingAs($this->admin)
        ->post(route('parties.merge', $this->survivor), ['merged_party_id' => $this->duplicate->id])
        ->assertSessionHasErrors('reason');

    expect(PartyAlias::count())->toBe(0);
});

it('posts no journal entry of its own — a merge is not a transaction', function () {
    ($this->invoice)($this->duplicate, 400_000);

    $entries = JournalEntry::count();

    $this->merges->merge($this->survivor, $this->duplicate, 'ادغام', $this->admin);

    expect(JournalEntry::count())->toBe($entries);
});
