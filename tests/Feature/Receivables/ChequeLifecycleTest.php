<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Cheque;
use App\Domain\Receivables\Services\ChequeService;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->customer = Party::createWithRole('customer', ['name' => 'مشتری چک‌دهنده']);
    $this->supplier = Party::createWithRole('supplier', ['name' => 'تأمین‌کننده']);
    $this->cheques = app(ChequeService::class);
    $this->due = Carbon::now('Asia/Tehran')->addDays(30);
});

/* ── The whole point of 1250/2100 ─────────────────────────────────────────── */

it('does not treat a received cheque as a payment', function () {
    $cheque = $this->cheques->registerReceivable($this->customer, 5_000_000, $this->due, '123456');

    // The customer has handed over a promise, not money. If registering a cheque touched
    // the bank account, every post-dated cheque in the drawer would read as cash.
    expect(bal('1250'))->toBe(5_000_000)
        ->and(bal($this->bank->account->code))->toBe(0)
        ->and($cheque->bank_account_id)->toBeNull()
        ->and($cheque->status)->toBe(Cheque::PENDING);
});

it('moves the promise into the bank only when it clears', function () {
    $cheque = $this->cheques->registerReceivable($this->customer, 5_000_000, $this->due, '123456');

    $cheque = $this->cheques->clear($cheque, $this->bank->id, $this->admin->id);

    expect($cheque->status)->toBe(Cheque::CLEARED)
        ->and(bal('1250'))->toBe(0)
        ->and(bal($this->bank->account->code))->toBe(5_000_000)
        ->and($cheque->bank_account_id)->toBe($this->bank->id)
        ->and($cheque->settled_by)->toBe($this->admin->id);
});

it('returns a bounced cheque to the receivable — the debt does not evaporate', function () {
    $cheque = $this->cheques->registerReceivable($this->customer, 5_000_000, $this->due, '123456');

    $this->cheques->bounce($cheque, $this->admin->id);

    // Back exactly where it was before the cheque existed: they still owe us.
    expect($cheque->fresh()->status)->toBe(Cheque::BOUNCED)
        ->and(bal('1250'))->toBe(0)
        ->and(bal($this->bank->account->code))->toBe(0);
});

it('clears a payable cheque out of our own account', function () {
    $cheque = $this->cheques->registerPayable($this->supplier, 3_000_000, $this->due, '999');

    expect(bal('2100'))->toBe(-3_000_000);  // liability: we owe on paper

    $this->cheques->clear($cheque, $this->bank->id, $this->admin->id);

    expect(bal('2100'))->toBe(0)
        ->and(bal($this->bank->account->code))->toBe(-3_000_000);
});

/* ── Cancellation and reversal ───────────────────────────────────────────── */

it('cancels a cheque that should never have been registered, restoring the receivable', function () {
    $cheque = $this->cheques->registerReceivable($this->customer, 5_000_000, $this->due, '123456');
    $original = $cheque->journalEntry;
    $originalLines = $original->lines->map->only(['account_id', 'debit', 'credit'])->toArray();

    $this->cheques->cancel($cheque, 'چک اشتباهی دو بار ثبت شده بود', $this->admin);

    $original->refresh()->load('lines');

    expect($cheque->fresh()->status)->toBe(Cheque::CANCELLED)
        // The registration entry is untouched; an opposing entry undoes it.
        ->and($original->lines->map->only(['account_id', 'debit', 'credit'])->toArray())->toBe($originalLines)
        ->and(bal('1250'))->toBe(0)
        ->and(bal('1200'))->toBe(0);
});

it('un-clears a cheque and lets it be settled again', function () {
    $cheque = $this->cheques->registerReceivable($this->customer, 5_000_000, $this->due, '123456');
    $this->cheques->clear($cheque, $this->bank->id, $this->admin->id);

    $cheque = $this->cheques->reverseSettlement($cheque->fresh(), 'وصول اشتباه ثبت شده بود', $this->admin);

    expect($cheque->status)->toBe(Cheque::PENDING)
        ->and($cheque->bank_account_id)->toBeNull()
        ->and(bal($this->bank->account->code))->toBe(0)
        ->and(bal('1250'))->toBe(5_000_000);

    // And it can be settled again — the second settlement must not collide with the
    // idempotency key of the one just reversed and silently post nothing at all.
    $this->cheques->bounce($cheque->fresh(), $this->admin->id);

    expect($cheque->fresh()->status)->toBe(Cheque::BOUNCED)
        ->and(bal('1250'))->toBe(0)
        ->and(bal('1200'))->toBe(0);
});

it('refuses to settle a cheque that is already settled', function () {
    $cheque = $this->cheques->registerReceivable($this->customer, 1_000_000, $this->due, '1');
    $this->cheques->clear($cheque, $this->bank->id);

    expect(fn () => $this->cheques->bounce($cheque->fresh()))->toThrow(InvalidArgumentException::class);
});

it('keeps the ledger balanced across the whole cheque lifecycle', function () {
    $a = $this->cheques->registerReceivable($this->customer, 5_000_000, $this->due, '1');
    $b = $this->cheques->registerPayable($this->supplier, 3_000_000, $this->due, '2');
    $c = $this->cheques->registerReceivable($this->customer, 2_000_000, $this->due, '3');

    $this->cheques->clear($a, $this->bank->id, $this->admin->id);
    $this->cheques->bounce($b, $this->admin->id);
    $this->cheques->cancel($c, 'اشتباه ثبت شد', $this->admin);

    $sums = JournalLine::selectRaw('SUM(debit) as d, SUM(credit) as c')->first();

    expect((int) $sums->d - (int) $sums->c)->toBe(0)
        ->and(JournalEntry::has('lines', '<', 2)->count())->toBe(0);
});

/* ── HTTP + permissions ──────────────────────────────────────────────────── */

it('gates the cheque pages by role', function () {
    foreach (['/cheques', '/cheques/create'] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->accountant)->get($url)->assertOk();
        $this->actingAs($this->warehouse)->get($url)->assertForbidden();
    }
});

it('registers, clears and reverses a cheque over HTTP', function () {
    $this->actingAs($this->accountant)->post('/cheques', [
        'party_id' => $this->customer->id,
        'direction' => Cheque::RECEIVABLE,
        'amount' => 4_000_000,
        'due_date' => $this->due->toDateString(),
        'serial' => '5551212',
        'bank_name' => 'ملت',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $cheque = Cheque::sole();

    expect($cheque->created_by)->toBe($this->accountant->id)
        ->and(bal('1250'))->toBe(4_000_000);

    $this->actingAs($this->accountant)->get("/cheques/{$cheque->id}")->assertOk();

    $this->actingAs($this->accountant)
        ->post("/cheques/{$cheque->id}/clear", ['bank_account_id' => $this->bank->id])
        ->assertSessionHasNoErrors();

    expect($cheque->fresh()->status)->toBe(Cheque::CLEARED);

    // An unexplained reversal is an unexplainable balance.
    $this->actingAs($this->admin)->post("/cheques/{$cheque->id}/reverse", [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)
        ->post("/cheques/{$cheque->id}/reverse", ['reason' => 'وصول اشتباه بود'])
        ->assertSessionHasNoErrors();

    expect($cheque->fresh()->status)->toBe(Cheque::PENDING)
        ->and(bal($this->bank->account->code))->toBe(0);
});

it('shows a party its own cheques on the unified profile', function () {
    $this->cheques->registerReceivable($this->customer, 5_000_000, $this->due, '123456');

    $this->actingAs($this->admin)
        ->get("/parties/{$this->customer->id}?tab=cheques")
        ->assertOk()
        ->assertSee('123456');
});
