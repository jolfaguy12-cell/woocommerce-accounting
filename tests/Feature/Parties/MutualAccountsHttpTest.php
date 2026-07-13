<?php

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\PartyOffset;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PartnerOperationType;
use App\Domain\Accounting\Support\PartyOffsetType;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    $this->party = Party::create(['type' => 'customer', 'name' => 'شرکت دوسویه']);
    $this->party->activateRole('supplier');

    $seed = function (AccountCode $code, string $side, int $amount) {
        $opposite = $side === 'debit' ? 'credit' : 'debit';

        app(JournalPoster::class)->post([
            'entry_date' => now(),
            'description' => 'مانده اولیه',
            'idempotency_key' => 'seed:'.uniqid(),
        ], [
            ['account' => $code, $side => $amount, 'party_id' => $this->party->id],
            ['account' => AccountCode::Capital, $opposite => $amount],
        ]);
    };

    $seed(AccountCode::AccountsReceivable, 'debit', 3_000_000);
    $seed(AccountCode::AccountsPayable, 'credit', 2_000_000);
});

it('repairs the /mutual-accounts links the sidebar has always pointed at', function () {
    // These two URLs sat in the menu for months with no route behind them.
    foreach (['/mutual-accounts', '/mutual-accounts/create'] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->accountant)->get($url)->assertOk();
        $this->actingAs($this->warehouse)->get($url)->assertForbidden();
    }
});

it('records an offset over HTTP and nets both balances', function () {
    $this->actingAs($this->accountant)->post('/mutual-accounts', [
        'party_id' => $this->party->id,
        'type' => PartyOffsetType::ReceivableAgainstPayable->value,
        'amount' => 2_000_000,
        'offset_date' => now()->toDateString(),
        'reason' => 'توافق تهاتر',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $ledger = app(PartyLedgerService::class);

    expect(PartyOffset::sole()->isPosted())->toBeTrue()
        ->and($ledger->customerReceivable($this->party))->toBe(1_000_000)
        ->and($ledger->supplierPayable($this->party))->toBe(0);
});

it('rejects an over-cap offset on the form rather than exploding', function () {
    $this->actingAs($this->admin)->post('/mutual-accounts', [
        'party_id' => $this->party->id,
        'type' => PartyOffsetType::ReceivableAgainstPayable->value,
        'amount' => 3_000_000, // payable is only 2m
        'offset_date' => now()->toDateString(),
        'reason' => 'تهاتر بیش از حد',
    ])->assertSessionHasErrors('amount');

    expect(PartyOffset::count())->toBe(0)
        ->and(app(PartyLedgerService::class)->supplierPayable($this->party))->toBe(2_000_000);
});

it('only offers parties that actually have something to offset', function () {
    Party::create(['type' => 'customer', 'name' => 'مشتری بدون مانده']);

    $candidates = $this->actingAs($this->admin)->get('/mutual-accounts/create')
        ->assertOk()->viewData('candidates');

    // A form listing every party would be a form whose every option but one is a dead end.
    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['name'])->toBe('شرکت دوسویه');
});

it('reverses an offset over HTTP, restoring both balances', function () {
    $this->actingAs($this->admin)->post('/mutual-accounts', [
        'party_id' => $this->party->id,
        'type' => PartyOffsetType::ReceivableAgainstPayable->value,
        'amount' => 2_000_000,
        'offset_date' => now()->toDateString(),
        'reason' => 'توافق تهاتر',
    ]);

    $offset = PartyOffset::sole();

    // A reason is not optional: an unexplained reversal is an unexplainable balance.
    $this->actingAs($this->admin)->post("/mutual-accounts/{$offset->id}/reverse", [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)->post("/mutual-accounts/{$offset->id}/reverse", ['reason' => 'توافق لغو شد'])
        ->assertSessionHasNoErrors();

    $ledger = app(PartyLedgerService::class);

    expect($offset->fresh()->isReversed())->toBeTrue()
        ->and($ledger->customerReceivable($this->party))->toBe(3_000_000)
        ->and($ledger->supplierPayable($this->party))->toBe(2_000_000);
});

it('gates partner operations and records one over HTTP', function () {
    $partner = Party::create(['type' => 'partner', 'name' => 'شریک اول']);

    $this->actingAs($this->warehouse)->get('/partner-operations')->assertForbidden();
    $this->actingAs($this->admin)->get('/partner-operations/create')->assertOk();

    $this->actingAs($this->accountant)->post('/partner-operations', [
        'party_id' => $partner->id,
        'type' => PartnerOperationType::Contribution->value,
        'amount' => 10_000_000,
        'operation_date' => now()->toDateString(),
        'description' => 'آورده نقدی',
        'bank_account_id' => $this->bank->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(app(PartyLedgerService::class)->partnerCapital($partner))->toBe(10_000_000)
        ->and($this->bank->account->balance())->toBe(10_000_000);
});

it('refuses a partner operation for a party without the partner role, on the form', function () {
    $this->actingAs($this->admin)->post('/partner-operations', [
        'party_id' => $this->party->id, // customer + supplier, but NOT a partner
        'type' => PartnerOperationType::Contribution->value,
        'amount' => 1_000_000,
        'operation_date' => now()->toDateString(),
        'description' => 'آورده',
        'bank_account_id' => $this->bank->id,
    ])->assertSessionHasErrors('amount');

    expect(app(PartyLedgerService::class)->partnerCapital($this->party))->toBe(0);
});
