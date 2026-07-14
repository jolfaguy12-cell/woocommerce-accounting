<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyBankAccount;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->party = Party::createWithRole('customer', ['name' => 'شرکت الف', 'phone' => '09121234567']);
});

it('renders the party list and profile for an admin', function () {
    $this->actingAs($this->admin)->get(route('parties.index'))
        ->assertOk()
        ->assertSee('شرکت الف');

    $this->actingAs($this->admin)->get(route('parties.show', $this->party))
        ->assertOk()
        ->assertSee('مشتری');
});

it('filters the party list by role', function () {
    $supplier = Party::createWithRole('supplier', ['name' => 'پخش تهران']);

    $this->actingAs($this->admin)->get(route('parties.index', ['role' => 'supplier']))
        ->assertOk()
        ->assertSee($supplier->name)
        ->assertDontSee($this->party->name);
});

it('activates and deactivates a role without touching the party or its ledger history', function () {
    app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'description' => 'فروش',
        'idempotency_key' => 'profile:ar',
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => 400_000, 'party_id' => $this->party->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => 400_000],
    ]);

    $this->actingAs($this->admin)
        ->post(route('parties.roles.activate', $this->party), ['role' => 'supplier'])
        ->assertRedirect();

    expect($this->party->fresh()->hasRole('supplier'))->toBeTrue();

    $this->actingAs($this->admin)
        ->post(route('parties.roles.deactivate', $this->party), ['role' => 'supplier'])
        ->assertRedirect();

    $party = $this->party->fresh();

    expect($party)->not->toBeNull()
        ->and($party->hasRole('supplier'))->toBeFalse()
        ->and($party->hasRole('customer'))->toBeTrue()
        ->and(app(PartyLedgerService::class)->customerReceivable($party))->toBe(400_000);
});

it('rejects an unknown role name from the UI', function () {
    $this->actingAs($this->admin)
        ->post(route('parties.roles.activate', $this->party), ['role' => 'lender'])
        ->assertSessionHasErrors('role');
});

it('shows the consolidated position as display-only and settles nothing', function () {
    app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'description' => 'فروش نسیه',
        'idempotency_key' => 'profile:consolidated',
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => 500_000, 'party_id' => $this->party->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => 500_000],
    ]);

    $this->actingAs($this->admin)->get(route('parties.show', $this->party))
        ->assertOk()
        ->assertSee('این مبلغ فقط یک نمای کلی است و به معنی تهاتر یا تسویه خودکار حساب‌ها نیست.');
});

it('renders the complete statement tab', function () {
    app(JournalPoster::class)->post([
        'entry_date' => Carbon::parse('2026-07-01', 'Asia/Tehran'),
        'description' => 'فروش نسیه شماره یک',
        'idempotency_key' => 'profile:statement',
    ], [
        ['account' => AccountCode::AccountsReceivable, 'debit' => 500_000, 'party_id' => $this->party->id],
        ['account' => AccountCode::SalesRevenue, 'credit' => 500_000, 'party_id' => $this->party->id],
    ]);

    $this->actingAs($this->admin)->get(route('parties.show', ['party' => $this->party, 'tab' => 'statement']))
        ->assertOk()
        ->assertSee('فروش نسیه شماره یک')
        ->assertSee('حساب‌های دریافتنی');
});

it('adds a counterparty bank account and deactivates it without deleting it', function () {
    $this->actingAs($this->admin)->post(route('parties.bank-accounts.store', $this->party), [
        'bank_name' => 'ملت',
        'account_holder' => 'شرکت الف',
        'iban' => 'IR820540102680020817909002',
    ])->assertRedirect();

    $account = PartyBankAccount::where('party_id', $this->party->id)->firstOrFail();

    expect($account->is_default)->toBeTrue(); // first one becomes the default

    $this->actingAs($this->admin)
        ->delete(route('parties.bank-accounts.destroy', [$this->party, $account]))
        ->assertRedirect();

    expect(PartyBankAccount::find($account->id))->not->toBeNull()
        ->and(PartyBankAccount::find($account->id)->is_active)->toBeFalse();
});

it('requires at least one account identifier on a counterparty bank account', function () {
    $this->actingAs($this->admin)
        ->post(route('parties.bank-accounts.store', $this->party), ['bank_name' => 'ملت'])
        ->assertSessionHasErrors('account_number');

    expect(PartyBankAccount::where('party_id', $this->party->id)->count())->toBe(0);
});

it('forbids a partner viewer from reading or changing party roles', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('partner_viewer');

    $this->actingAs($viewer)->get(route('parties.index'))->assertForbidden();
    $this->actingAs($viewer)->get(route('parties.show', $this->party))->assertForbidden();
    $this->actingAs($viewer)
        ->post(route('parties.roles.activate', $this->party), ['role' => 'supplier'])
        ->assertForbidden();

    expect($this->party->fresh()->hasRole('supplier'))->toBeFalse();
});

it('forbids a warehouse user from changing party roles', function () {
    $warehouse = User::factory()->create();
    $warehouse->assignRole('warehouse');

    $this->actingAs($warehouse)
        ->post(route('parties.roles.activate', $this->party), ['role' => 'supplier'])
        ->assertForbidden();
});
