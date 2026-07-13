<?php

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\PartnerOperation;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\PartnerOperationService;
use App\Domain\Accounting\Services\PartyLedgerService;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\PartnerOperationType;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Services\LoanService;
use App\Domain\Receivables\Support\InterestMethod;
use App\Domain\Receivables\Support\LoanDirection;
use App\Domain\Receivables\Support\LoanStatus;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->accountant = User::factory()->create()->assignRole('accountant');
    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);

    $this->partner = Party::create(['type' => 'partner', 'name' => 'شریک اول']);
    $this->operations = app(PartnerOperationService::class);
    $this->ledger = app(PartyLedgerService::class);
});

/* ── Partner loans are LOANS ─────────────────────────────────────────────── */

it('creates a real Loan contract with a schedule when a partner lends us money', function () {
    // Before this, the operation posted straight to 2200 with no Loan behind it: the money
    // was in the ledger, and the "loan" had no maturity, no interest and no schedule —
    // nothing to repay against, and nothing to say a repayment was ever due.
    $operation = $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::LoanFromPartner,
        'amount' => 60_000_000,
        'operation_date' => now(),
        'description' => 'وام شریک برای سرمایه در گردش',
        'bank_account_id' => $this->bank->id,
        'interest_method' => InterestMethod::Fixed->value,
        'interest_amount' => 6_000_000,
        'installment_count' => 6,
        'created_by' => $this->admin->id,
    ]);

    $loan = $operation->fresh()->loan;

    expect($loan)->not->toBeNull()
        ->and($loan->direction)->toBe(LoanDirection::Payable)   // they lent to us
        ->and($loan->status)->toBe(LoanStatus::Active)
        ->and($loan->principal)->toBe(60_000_000)
        ->and($loan->installments)->toHaveCount(6)
        ->and($loan->installments->sum('principal_part'))->toBe(60_000_000)
        ->and($loan->installments->sum('interest_part'))->toBe(6_000_000);
});

it('posts exactly ONE journal entry for a partner loan — not one per domain', function () {
    $operation = $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::LoanFromPartner,
        'amount' => 60_000_000,
        'operation_date' => now(),
        'description' => 'وام شریک',
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->admin->id,
    ])->fresh();

    // The trap: the partner operation posting its own partner-shaped entry AND the loan
    // posting the disbursement. Both balance perfectly, and together they put 120,000,000
    // in the bank for a 60,000,000 loan.
    expect(JournalEntry::count())->toBe(1)
        ->and($operation->journal_entry_id)->toBe($operation->loan->journal_entry_id)
        ->and($this->bank->account->balance())->toBe(60_000_000)
        ->and($this->ledger->loanPayable($this->partner))->toBe(60_000_000);
});

it('lends TO a partner through the same path, landing on 1600', function () {
    $operation = $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::LoanToPartner,
        'amount' => 20_000_000,
        'operation_date' => now(),
        'description' => 'وام به شریک',
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->admin->id,
    ])->fresh();

    expect($operation->loan->direction)->toBe(LoanDirection::Receivable)
        ->and($this->ledger->loanReceivable($this->partner))->toBe(20_000_000);
});

it('repays a partner loan through the loan, and the partner statement follows', function () {
    $operation = $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::LoanFromPartner,
        'amount' => 10_000_000,
        'operation_date' => now(),
        'description' => 'وام شریک',
        'bank_account_id' => $this->bank->id,
        'installment_count' => 2,
        'created_by' => $this->admin->id,
    ])->fresh();

    $loans = app(LoanService::class);
    $loan = $operation->loan;

    $loans->payInstallment($loan, 5_000_000, 5_000_000, $this->bank->id, now(), 0, 0, $loan->installments->first());

    expect($loans->remainingPrincipal($loan->fresh()))->toBe(5_000_000)
        // The partner's own statement is the same ledger, read from the party's side.
        ->and($this->ledger->loanPayable($this->partner))->toBe(5_000_000);
});

it('reverses the partner loan and its contract together, posting only ONE reversal', function () {
    $operation = $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::LoanFromPartner,
        'amount' => 10_000_000,
        'operation_date' => now(),
        'description' => 'وام شریک',
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->accountant->id,
    ])->fresh();

    $this->operations->reverse($operation, 'وام لغو شد', $this->admin);

    // Two reversals of one shared entry would hand the money back twice.
    expect(JournalEntry::count())->toBe(2)
        ->and($operation->fresh()->isReversed())->toBeTrue()
        ->and($operation->fresh()->loan->status)->toBe(LoanStatus::Reversed)
        ->and($this->bank->account->balance())->toBe(0)
        ->and($this->ledger->loanPayable($this->partner))->toBe(0);
});

it('refuses to reverse a partner loan that has been partly repaid', function () {
    $operation = $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::LoanFromPartner,
        'amount' => 10_000_000,
        'operation_date' => now(),
        'description' => 'وام شریک',
        'bank_account_id' => $this->bank->id,
        'installment_count' => 2,
        'created_by' => $this->accountant->id,
    ])->fresh();

    $loan = $operation->loan;
    app(LoanService::class)->payInstallment($loan, 5_000_000, 5_000_000, $this->bank->id, now(), 0, 0, $loan->installments->first());

    expect(fn () => $this->operations->reverse($operation->fresh(), 'اشتباه بود', $this->admin))
        ->toThrow(OperationStateException::class);

    expect($operation->fresh()->isPosted())->toBeTrue();
});

/* ── Profit distribution comes out of RETAINED EARNINGS, not capital ─────── */

it('takes a declared profit share out of retained earnings, leaving capital untouched', function () {
    // The partner puts in 100m…
    $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::Contribution,
        'amount' => 100_000_000,
        'operation_date' => now(),
        'description' => 'آورده نقدی',
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->admin->id,
    ]);

    // …and is then awarded 30m of the company's earnings.
    $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::ProfitDistribution,
        'amount' => 30_000_000,
        'operation_date' => now(),
        'description' => 'سهم سود سال ۱۴۰۵',
        'created_by' => $this->admin->id,
    ]);

    // Their STAKE is still 100m. Paying a partner their earnings does not give back any
    // of what they put in — debiting capital here would make a founder who invested 100m
    // and was paid 100m of earnings read as owning nothing at all.
    expect($this->ledger->partnerCapital($this->partner))->toBe(100_000_000)
        ->and($this->ledger->partnerProfitPayable($this->partner))->toBe(30_000_000)
        // Retained earnings carries the cost of the declaration (equity, credit-natural,
        // so a debit of 30m reads as −30m).
        ->and(AccountCode::RetainedEarnings->account()->balance())->toBe(30_000_000);
});

it('pays out a declared share («پرداخت سود شریک») and refuses to pay more than was declared', function () {
    $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::ProfitDistribution,
        'amount' => 10_000_000,
        'operation_date' => now(),
        'description' => 'سهم سود',
        'created_by' => $this->admin->id,
    ]);

    // Paying more than was declared does not clear a balance — it pushes 2500 negative and
    // quietly turns the partner into our debtor.
    expect(fn () => $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::ProfitPayablePayment,
        'amount' => 15_000_000,
        'operation_date' => now(),
        'description' => 'پرداخت سود',
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->admin->id,
    ]))->toThrow(InvalidArgumentException::class);

    $this->operations->create([
        'party' => $this->partner,
        'type' => PartnerOperationType::ProfitPayablePayment,
        'amount' => 10_000_000,
        'operation_date' => now(),
        'description' => 'پرداخت سود',
        'bank_account_id' => $this->bank->id,
        'created_by' => $this->admin->id,
    ]);

    expect($this->ledger->partnerProfitPayable($this->partner))->toBe(0)
        ->and($this->bank->account->balance())->toBe(-10_000_000);
});

it('calls the payout «پرداخت سود شریک» — the account name is not the action name', function () {
    expect(PartnerOperationType::ProfitPayablePayment->label())->toBe('پرداخت سود شریک')
        ->and(PartnerOperationType::ProfitDistribution->label())->toBe('توزیع سود');
});

/* ── HTTP ────────────────────────────────────────────────────────────────── */

it('records a partner loan over HTTP and links the operation to its loan', function () {
    $this->actingAs($this->accountant)->post('/partner-operations', [
        'party_id' => $this->partner->id,
        'type' => PartnerOperationType::LoanFromPartner->value,
        'amount' => 30_000_000,
        'operation_date' => now()->toDateString(),
        'description' => 'وام شریک',
        'bank_account_id' => $this->bank->id,
        'interest_method' => InterestMethod::None->value,
        'installment_count' => 3,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $operation = PartnerOperation::sole();

    expect($operation->loan_id)->not->toBeNull()
        ->and(Loan::count())->toBe(1)
        ->and(JournalEntry::count())->toBe(1)
        ->and($operation->loan->installments)->toHaveCount(3);

    // …and the loan shows up on the partner's own profile.
    $this->actingAs($this->admin)
        ->get("/parties/{$this->partner->id}?tab=loans")
        ->assertOk()
        ->assertSee('وام دریافتی');
});
