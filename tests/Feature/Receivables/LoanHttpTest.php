<?php

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Services\LoanService;
use App\Domain\Receivables\Support\InterestMethod;
use App\Domain\Receivables\Support\LoanDirection;
use App\Domain\Receivables\Support\LoanStatus;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->warehouse = User::factory()->create()->assignRole('warehouse');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->party = Party::createWithRole('other', ['name' => 'بانک وام‌دهنده']);
});

it('gates the loan pages by role', function () {
    foreach (['/loans', '/loans/create'] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->accountant)->get($url)->assertOk();
        $this->actingAs($this->warehouse)->get($url)->assertForbidden();
    }
});

it('registers a loan with a schedule over HTTP', function () {
    $this->actingAs($this->accountant)->post('/loans', [
        'party_id' => $this->party->id,
        'direction' => LoanDirection::Payable->value,
        'principal' => 24_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => now()->toDateString(),
        'interest_method' => InterestMethod::Flat->value,
        'interest_rate' => 18,
        'installment_count' => 12,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $loan = Loan::sole();

    expect($loan->status)->toBe(LoanStatus::Active)
        ->and($loan->created_by)->toBe($this->accountant->id)
        ->and($loan->installments)->toHaveCount(12)
        ->and($loan->interest_amount)->toBe(4_320_000)  // 24m × 18% × 12/12
        ->and($this->bank->account->balance())->toBe(24_000_000);

    $this->actingAs($this->accountant)->get("/loans/{$loan->id}")->assertOk()->assertSee('برنامه اقساط');
});

it('pays an installment over HTTP and shows the remaining principal from the ledger', function () {
    $loan = app(LoanService::class)->create([
        'party' => $this->party,
        'direction' => LoanDirection::Payable,
        'principal' => 10_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => Carbon::now('Asia/Tehran'),
        'installment_count' => 2,
    ]);

    $installment = $loan->installments->first();

    $this->actingAs($this->accountant)->post("/loans/{$loan->id}/installments", [
        'installment_id' => $installment->id,
        'amount' => 5_400_000,
        'principal_part' => 5_000_000,
        'fee_part' => 100_000,
        'penalty_part' => 300_000,
        'bank_account_id' => $this->bank->id,
        'paid_at' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $summary = $this->actingAs($this->admin)->get("/loans/{$loan->id}")->assertOk()->viewData('summary');

    expect($summary['remaining_principal'])->toBe(5_000_000)
        ->and($summary['paid_fee'])->toBe(100_000)
        ->and($summary['paid_penalty'])->toBe(300_000)
        ->and($summary['next_due_fa'])->not->toBeNull();
});

it('rejects an over-payment on the form rather than exploding', function () {
    $loan = app(LoanService::class)->receive($this->party, 1_000_000, $this->bank->id, Carbon::now('Asia/Tehran'));

    $this->actingAs($this->accountant)->post("/loans/{$loan->id}/installments", [
        'amount' => 2_000_000,
        'principal_part' => 2_000_000,
        'bank_account_id' => $this->bank->id,
        'paid_at' => now()->toDateString(),
    ])->assertSessionHasErrors('amount');

    expect(app(LoanService::class)->remainingPrincipal($loan->fresh()))->toBe(1_000_000);
});

it('reverses a loan over HTTP, demanding a reason', function () {
    $loan = app(LoanService::class)->receive($this->party, 7_000_000, $this->bank->id, Carbon::now('Asia/Tehran'));

    // An unexplained reversal is an unexplainable balance.
    $this->actingAs($this->admin)->post("/loans/{$loan->id}/reverse", [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->admin)->post("/loans/{$loan->id}/reverse", ['reason' => 'قرارداد فسخ شد'])
        ->assertSessionHasNoErrors();

    expect($loan->fresh()->status)->toBe(LoanStatus::Reversed)
        ->and($this->bank->account->balance())->toBe(0)
        ->and(JournalEntry::count())->toBe(2); // the original, and the one that undoes it
});

it('will not let an accountant reverse a loan (reversal is admin-only by default)', function () {
    $loan = app(LoanService::class)->receive($this->party, 7_000_000, $this->bank->id, Carbon::now('Asia/Tehran'));

    $this->actingAs($this->accountant)->post("/loans/{$loan->id}/reverse", ['reason' => 'تلاش برای برگشت'])
        ->assertSessionHasErrors('loan');

    expect($loan->fresh()->status)->toBe(LoanStatus::Active);
});

it('shows a party its own loans on the unified profile', function () {
    app(LoanService::class)->give($this->party, 3_000_000, $this->bank->id, Carbon::now('Asia/Tehran'));

    $this->actingAs($this->admin)
        ->get("/parties/{$this->party->id}?tab=loans")
        ->assertOk()
        ->assertSee('وام پرداختی')          // we lent it out
        ->assertSee('مانده اصل وام');
});

it('offers a working "new loan" and "new cheque" button on the profile tabs', function () {
    // A named slot on a component that does not RENDER that slot compiles fine and shows
    // nothing — so the button silently disappears and no page-loads-OK test would notice.
    $this->actingAs($this->admin)
        ->get("/parties/{$this->party->id}?tab=loans")
        ->assertOk()
        ->assertSee(route('loans.create'));

    $this->actingAs($this->admin)
        ->get("/parties/{$this->party->id}?tab=cheques")
        ->assertOk()
        ->assertSee(route('cheques.create'));
});
