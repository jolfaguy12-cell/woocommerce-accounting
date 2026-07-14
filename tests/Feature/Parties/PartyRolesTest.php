<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyBankAccount;
use App\Domain\Accounting\Models\PartyRole;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PartyIdentityBackfill;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('gives a newly created party exactly the role it was created with', function () {
    $party = Party::createWithRole('customer', ['name' => 'زهرا محمدی']);

    expect($party->hasRole('customer'))->toBeTrue()
        ->and($party->hasRole(PartyRoleType::Supplier))->toBeFalse()
        ->and($party->roles()->count())->toBe(1);
});

it('lets one party hold customer and supplier roles at the same time', function () {
    $party = Party::createWithRole('customer', ['name' => 'شرکت الف']);

    $party->activateRole(PartyRoleType::Supplier);

    expect($party->hasRole('customer'))->toBeTrue()
        ->and($party->hasRole('supplier'))->toBeTrue()
        ->and(Party::withRole('customer')->pluck('id')->all())->toContain($party->id)
        ->and(Party::withRole('supplier')->pluck('id')->all())->toContain($party->id);
});

it('never creates a second row for a role the party already has', function () {
    $party = Party::createWithRole('supplier', ['name' => 'پخش تهران']);

    $party->activateRole('supplier');
    $party->activateRole('supplier');

    expect(PartyRole::where('party_id', $party->id)->where('role', 'supplier')->count())->toBe(1);
});

it('rejects a duplicate (party, role) row at the database level', function () {
    $party = Party::createWithRole('customer', ['name' => 'مهمان']);

    DB::table('party_roles')->insert([
        'party_id' => $party->id,
        'role' => 'customer',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('rejects an unknown role name', function () {
    Party::createWithRole('customer', ['name' => 'مهمان'])->activateRole('lender');
})->throws(InvalidArgumentException::class);

it('keeps the party, its other roles and its journal history when a role is deactivated', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $party = Party::createWithRole('customer', ['name' => 'شرکت ب']);
    $party->activateRole('supplier');

    app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'description' => 'فروش نسیه',
        'idempotency_key' => 'test:party-role-history',
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => 500_000, 'party_id' => $party->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => 500_000],
    ]);

    $linesBefore = DB::table('journal_lines')->where('party_id', $party->id)->count();

    $party->deactivateRole('supplier');

    $role = PartyRole::where('party_id', $party->id)->where('role', 'supplier')->first();

    expect(Party::find($party->id))->not->toBeNull()
        ->and($role->is_active)->toBeFalse()
        ->and($role->deactivated_at)->not->toBeNull()
        ->and($party->fresh()->hasRole('supplier'))->toBeFalse()
        ->and($party->fresh()->hasRole('customer'))->toBeTrue()
        ->and(DB::table('journal_lines')->where('party_id', $party->id)->count())->toBe($linesBefore)
        ->and(Party::withRole('supplier')->pluck('id')->all())->not->toContain($party->id);
});

it('reactivates a deactivated role on the same row, recording who did it', function () {
    $user = User::factory()->create();
    $party = Party::createWithRole('customer', ['name' => 'شرکت ج']);

    $party->activateRole('supplier', $user->id);
    $party->deactivateRole('supplier', $user->id);
    $party->activateRole('supplier', $user->id);

    $roles = PartyRole::where('party_id', $party->id)->where('role', 'supplier')->get();

    expect($roles)->toHaveCount(1)
        ->and($roles->first()->is_active)->toBeTrue()
        ->and($roles->first()->deactivated_at)->toBeNull()
        ->and($roles->first()->activated_by)->toBe($user->id);
});

it('writes role changes to the activity log', function () {
    $party = Party::createWithRole('customer', ['name' => 'شرکت د']);
    $party->activateRole('partner');
    $party->deactivateRole('partner');

    expect(Activity::where('subject_type', 'party_role')->count())->toBeGreaterThan(0);
});

it('normalizes a phone number on save, and backfills rows that arrived without one', function () {
    $party = Party::createWithRole('customer', ['name' => 'رضا', 'phone' => '+989121234567']);

    expect($party->normalized_phone)->toBe('09121234567');

    // Straight into the table, bypassing the model — that is how a party arrives
    // from a bulk import, and it is the only way normalized_phone can be null.
    DB::table('parties')->insert([
        'name' => 'لگاسی', 'phone' => '۰۰۹۸۹۱۲۹۹۹۸۸۷۷',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(PartyIdentityBackfill::normalizedPhones())->toBe(1)
        ->and(DB::table('parties')->where('name', 'لگاسی')->value('normalized_phone'))->toBe('09129998877');
});

it('stores counterparty bank accounts without any link to an internal ledger account', function () {
    $party = Party::createWithRole('supplier', ['name' => 'پخش شرق']);

    $account = PartyBankAccount::create([
        'party_id' => $party->id,
        'bank_name' => 'ملت',
        'account_holder' => 'پخش شرق',
        'iban' => 'IR820540102680020817909002',
        'is_default' => true,
    ]);

    expect($party->bankAccounts()->count())->toBe(1)
        ->and($account->getAttributes())->not->toHaveKey('account_id');
});

it('seeds every account code the registry declares', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    foreach (AccountCode::cases() as $code) {
        expect($code->account()->code)->toBe($code->value);
    }
});

it('gives a role its profile the moment the role is activated', function () {
    $party = Party::createWithRole('other', ['name' => 'شرکت ز']);

    expect($party->customerProfile()->exists())->toBeFalse();

    $party->activateRole('customer');

    expect($party->fresh()->customerProfile)->not->toBeNull()
        ->and($party->fresh()->is_wholesale)->toBeFalse();
});

it('keeps the role profile and its data through deactivation and reactivation', function () {
    $party = Party::createWithRole('customer', ['name' => 'شرکت ی']);
    $profile = $party->profileFor('customer');
    $profile->update(['credit_limit' => 7_500_000, 'is_wholesale' => true]);

    $party->deactivateRole('customer');

    // Deactivation flags the role. It does not delete the profile.
    expect($party->fresh()->customerProfile)->not->toBeNull()
        ->and($party->fresh()->customerProfile->credit_limit)->toBe(7_500_000);

    $party->activateRole('customer');

    $reactivated = $party->fresh();

    expect($reactivated->customerProfile->id)->toBe($profile->id) // same row, not a fresh blank one
        ->and($reactivated->credit_limit)->toBe(7_500_000)
        ->and($reactivated->is_wholesale)->toBeTrue()
        ->and($reactivated->customerProfile()->count())->toBe(1);
});

it('creates the role and its profile atomically', function () {
    $party = Party::createWithRole('other', ['name' => 'شرکت اتمی']);

    $party->activateRole('supplier');

    // Never a role without its profile: a whereHas filter cannot see such a party.
    expect($party->fresh()->hasRole('supplier'))->toBeTrue()
        ->and($party->fresh()->supplierProfile)->not->toBeNull()
        ->and(Party::withRole('supplier')->whereHas('supplierProfile')->pluck('id')->all())->toContain($party->id);
});

it('adopts the winning row when two activations race for the same role', function () {
    $party = Party::createWithRole('customer', ['name' => 'شرکت مسابقه']);

    // Simulate the loser of the race: the row already exists (inserted by the
    // other process) when this activation tries to write it.
    DB::table('party_roles')->insert([
        'party_id' => $party->id, 'role' => 'partner', 'is_active' => true,
        'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $role = $party->activateRole('partner');

    expect($role->is_active)->toBeTrue()
        ->and(PartyRole::where('party_id', $party->id)->where('role', 'partner')->count())->toBe(1)
        ->and($party->fresh()->partnerProfile)->not->toBeNull();
});
