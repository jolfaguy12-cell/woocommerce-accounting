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

it('gives a newly created party the role of its legacy type', function () {
    $party = Party::create(['type' => 'customer', 'name' => 'زهرا محمدی']);

    expect($party->hasRole('customer'))->toBeTrue()
        ->and($party->hasRole(PartyRoleType::Supplier))->toBeFalse()
        ->and($party->roles()->count())->toBe(1);
});

it('lets one party hold customer and supplier roles at the same time', function () {
    $party = Party::create(['type' => 'customer', 'name' => 'شرکت الف']);

    $party->activateRole(PartyRoleType::Supplier);

    expect($party->hasRole('customer'))->toBeTrue()
        ->and($party->hasRole('supplier'))->toBeTrue()
        ->and(Party::withRole('customer')->pluck('id')->all())->toContain($party->id)
        ->and(Party::withRole('supplier')->pluck('id')->all())->toContain($party->id);
});

it('never creates a second row for a role the party already has', function () {
    $party = Party::create(['type' => 'supplier', 'name' => 'پخش تهران']);

    $party->activateRole('supplier');
    $party->activateRole('supplier');

    expect(PartyRole::where('party_id', $party->id)->where('role', 'supplier')->count())->toBe(1);
});

it('rejects a duplicate (party, role) row at the database level', function () {
    $party = Party::create(['type' => 'customer', 'name' => 'مهمان']);

    DB::table('party_roles')->insert([
        'party_id' => $party->id,
        'role' => 'customer',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('rejects an unknown role name', function () {
    Party::create(['type' => 'customer', 'name' => 'مهمان'])->activateRole('lender');
})->throws(InvalidArgumentException::class);

it('keeps the party, its other roles and its journal history when a role is deactivated', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $party = Party::create(['type' => 'customer', 'name' => 'شرکت ب']);
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
    $party = Party::create(['type' => 'customer', 'name' => 'شرکت ج']);

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
    $party = Party::create(['type' => 'customer', 'name' => 'شرکت د']);
    $party->activateRole('partner');
    $party->deactivateRole('partner');

    expect(Activity::where('subject_type', 'party_role')->count())->toBeGreaterThan(0);
});

it('backfills one role per legacy party type and is safely re-runnable', function () {
    // Insert straight through the query builder: no model events, so these rows
    // look exactly like the 1094 production parties that predate party_roles.
    foreach ([['customer', 'الف'], ['supplier', 'ب'], ['employee', 'ج'], ['partner', 'د'], ['other', 'ه']] as [$type, $name]) {
        DB::table('parties')->insert([
            'type' => $type, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::table('party_roles')->count())->toBe(0);

    $created = PartyIdentityBackfill::roles();

    expect($created)->toBe(5)
        ->and(DB::table('party_roles')->count())->toBe(DB::table('parties')->count());

    foreach (DB::table('parties')->get() as $party) {
        $role = DB::table('party_roles')->where('party_id', $party->id)->first();
        expect($role->role)->toBe($party->type)
            ->and((bool) $role->is_active)->toBeTrue();
    }

    // Re-running must not duplicate anything — it runs again at deploy time,
    // after production has minted more parties from order sync.
    expect(PartyIdentityBackfill::roles())->toBe(0)
        ->and(DB::table('party_roles')->count())->toBe(5);
});

it('normalizes a phone number on save and backfills legacy rows', function () {
    $party = Party::create(['type' => 'customer', 'name' => 'رضا', 'phone' => '+989121234567']);

    expect($party->normalized_phone)->toBe('09121234567');

    DB::table('parties')->insert([
        'type' => 'customer', 'name' => 'لگاسی', 'phone' => '۰۰۹۸۹۱۲۹۹۹۸۸۷۷',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(PartyIdentityBackfill::normalizedPhones())->toBe(1)
        ->and(DB::table('parties')->where('name', 'لگاسی')->value('normalized_phone'))->toBe('09129998877');
});

it('stores counterparty bank accounts without any link to an internal ledger account', function () {
    $party = Party::create(['type' => 'supplier', 'name' => 'پخش شرق']);

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
