<?php

use App\Domain\Accounting\Models\AccountTransfer;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Services\AccountTransferService;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->secondAdmin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');

    $this->source = app(BankAccountManager::class)->create(['name' => 'صندوق', 'is_cash' => true]);
    $this->destination = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    app(JournalPoster::class)->post([
        'entry_date' => now(),
        'description' => 'موجودی اولیه',
        'idempotency_key' => 'seed:http',
    ], [
        ['account' => $this->source->account_id, 'debit' => 10_000_000],
        ['account' => AccountCode::Capital, 'credit' => 10_000_000],
    ]);

    $this->payload = [
        'type' => 'transfer',
        'from_bank_account_id' => $this->source->id,
        'to_bank_account_id' => $this->destination->id,
        'amount' => 2_000_000,
        'transfer_date' => now()->toDateString(),
        'method' => 'internal',
    ];
});

it('gates the whole feature to admin and accountant', function () {
    foreach (['/financial-operations', '/financial-operations/create'] as $url) {
        $this->actingAs($this->warehouse)->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->accountant)->get($url)->assertOk();
    }

    $this->actingAs($this->warehouse)->post('/financial-operations', $this->payload)->assertForbidden();
});

it('records a transfer over HTTP and posts it straight away when no approval threshold is set', function () {
    $this->actingAs($this->accountant)
        ->post('/financial-operations', $this->payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $transfer = AccountTransfer::sole();

    expect($transfer->isPosted())->toBeTrue()
        ->and($transfer->created_by)->toBe($this->accountant->id)
        ->and($this->source->account->balance())->toBe(8_000_000);
});

it('rejects a same-account transfer on the form, not with a 500', function () {
    $this->actingAs($this->admin)
        ->post('/financial-operations', array_merge($this->payload, ['to_bank_account_id' => $this->source->id]))
        ->assertSessionHasErrors('to_bank_account_id');

    expect(AccountTransfer::count())->toBe(0);
});

it('holds an operation at or above the approval threshold, and posts nothing until someone else approves', function () {
    Setting::set(OperationPolicy::APPROVAL_THRESHOLD, 1_000_000);
    $entriesBefore = JournalEntry::count();

    $this->actingAs($this->accountant)->post('/financial-operations', $this->payload)->assertRedirect();

    $transfer = AccountTransfer::sole();

    // Pending means pending: not a single line has reached the ledger.
    expect($transfer->isPendingApproval())->toBeTrue()
        ->and($transfer->journal_entry_id)->toBeNull()
        ->and(JournalEntry::count())->toBe($entriesBefore)
        ->and($this->source->account->balance())->toBe(10_000_000);

    $this->actingAs($this->admin)
        ->post("/financial-operations/transfers/{$transfer->id}/approve")
        ->assertRedirect()->assertSessionHasNoErrors();

    $transfer->refresh();

    expect($transfer->isPosted())->toBeTrue()
        ->and($transfer->approved_by)->toBe($this->admin->id)
        ->and($this->source->account->balance())->toBe(8_000_000);
});

it('lets the creator approve their own operation — the threshold is a prompt, not a two-person rule', function () {
    Setting::set(OperationPolicy::APPROVAL_THRESHOLD, 1_000_000);

    // This is often a one-bookkeeper business. Requiring a second human to exist does
    // not make the books safer when there is no second human; it makes the operation
    // impossible to post, and the work moves somewhere nothing is recorded at all.
    // The real protections do not need two people: attribution, the activity log,
    // immutable entries, and correction by reversal.
    $this->actingAs($this->admin)->post('/financial-operations', $this->payload);
    $transfer = AccountTransfer::sole();

    $this->actingAs($this->admin)
        ->post("/financial-operations/transfers/{$transfer->id}/approve")
        ->assertSessionHasNoErrors();

    expect($transfer->fresh()->isPosted())->toBeTrue()
        ->and($transfer->fresh()->approved_by)->toBe($this->admin->id);

});

it('lets only the approve-role approve, by default admin and not accountant', function () {
    Setting::set(OperationPolicy::APPROVAL_THRESHOLD, 1_000_000);

    $this->actingAs($this->admin)->post('/financial-operations', $this->payload);
    $transfer = AccountTransfer::sole();

    $this->actingAs($this->accountant)
        ->post("/financial-operations/transfers/{$transfer->id}/approve")
        ->assertSessionHasErrors('operation');

    expect($transfer->fresh()->isPendingApproval())->toBeTrue();
});

it('cancels a pending operation without any accounting consequence', function () {
    Setting::set(OperationPolicy::APPROVAL_THRESHOLD, 1_000_000);
    $entriesBefore = JournalEntry::count();

    $this->actingAs($this->accountant)->post('/financial-operations', $this->payload);
    $transfer = AccountTransfer::sole();

    $this->actingAs($this->accountant)
        ->post("/financial-operations/transfers/{$transfer->id}/cancel", ['reason' => 'اشتباه وارد شد'])
        ->assertSessionHasNoErrors();

    expect($transfer->fresh()->isCancelled())->toBeTrue()
        ->and(JournalEntry::count())->toBe($entriesBefore);
});

it('reverses a posted operation over HTTP, and only the reverse-role may do it', function () {
    $transfer = app(AccountTransferService::class)->create([
        'from_bank_account_id' => $this->source->id,
        'to_bank_account_id' => $this->destination->id,
        'amount' => 2_000_000,
        'transfer_date' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->accountant)
        ->post("/financial-operations/transfers/{$transfer->id}/reverse", ['reason' => 'اشتباه'])
        ->assertSessionHasErrors('operation');

    expect($transfer->fresh()->isPosted())->toBeTrue();

    // A reason is not optional: an unexplained reversal is an unexplainable number.
    $this->actingAs($this->admin)
        ->post("/financial-operations/transfers/{$transfer->id}/reverse", [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)
        ->post("/financial-operations/transfers/{$transfer->id}/reverse", ['reason' => 'حساب مقصد اشتباه بود'])
        ->assertSessionHasNoErrors();

    expect($transfer->fresh()->isReversed())->toBeTrue()
        ->and($this->source->account->balance())->toBe(10_000_000);
});

it('cannot cancel an operation that is already in the ledger', function () {
    $transfer = app(AccountTransferService::class)->create([
        'from_bank_account_id' => $this->source->id,
        'to_bank_account_id' => $this->destination->id,
        'amount' => 2_000_000,
        'transfer_date' => now(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/financial-operations/transfers/{$transfer->id}/cancel", ['reason' => 'نظرم عوض شد'])
        ->assertSessionHasErrors('operation');

    expect($transfer->fresh()->isPosted())->toBeTrue();
});

it('shows the operation with its journal entry and lists it', function () {
    $this->actingAs($this->admin)->post('/financial-operations', array_merge($this->payload, ['bank_fee' => 15_000]));
    $transfer = AccountTransfer::sole();

    $this->actingAs($this->admin)->get("/financial-operations/transfers/{$transfer->id}")
        ->assertOk()
        ->assertSee('انتقال به بانک ملت')      // the source line's memo
        ->assertSee('کارمزد انتقال به بانک ملت') // the fee line
        ->assertSee(AccountCode::BankFee->value);

    $this->actingAs($this->admin)->get('/financial-operations')
        ->assertOk()
        ->assertSee('انتقال بین حساب‌ها');
});
