<?php

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Models\Setting;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Expenses\Services\BankAccountManager;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Models\LoanInstallment;
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

    $this->bank = app(BankAccountManager::class)->create(['name' => 'بانک ملت']);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->other = User::factory()->create()->assignRole('admin');
    $this->borrower = Party::create(['type' => 'other', 'name' => 'وام‌گیرنده']);
    $this->loans = app(LoanService::class);
    $this->now = Carbon::now('Asia/Tehran');
});

function bal(string $code): int
{
    $account = Account::firstWhere('code', $code);

    return (int) $account->lines()->sum('debit') - (int) $account->lines()->sum('credit');
}

/* ── Loan given (receivable) ─────────────────────────────────────────────── */

it('gives a loan: our cash leaves and their obligation to us appears on 1600', function () {
    $this->bank->account->lines()->getRelated(); // no-op; balance starts at 0

    $loan = $this->loans->give($this->borrower, 50_000_000, $this->bank->id, $this->now);

    expect($loan->status)->toBe(LoanStatus::Active)
        ->and($loan->direction)->toBe(LoanDirection::Receivable)
        ->and(bal('1600'))->toBe(50_000_000)                        // asset: they owe us
        ->and(bal($this->bank->account->code))->toBe(-50_000_000)   // the money left
        ->and($this->loans->remainingPrincipal($loan))->toBe(50_000_000);
});

it('receives an installment on a loan we gave: principal reduces 1600, interest is INCOME', function () {
    $loan = $this->loans->give($this->borrower, 10_000_000, $this->bank->id, $this->now);

    // 3,000,000 arrives: 2,500,000 of principal, 400,000 interest, 60,000 fee, 40,000 penalty.
    $this->loans->receiveInstallment($loan, 3_000_000, 2_500_000, $this->bank->id, $this->now, 60_000, 40_000);

    expect($this->loans->remainingPrincipal($loan->fresh()))->toBe(7_500_000)
        ->and(bal('4200'))->toBe(-460_000)  // interest + fee: revenue is credit-natural
        ->and(bal('4900'))->toBe(-40_000)   // the penalty we COLLECTED is other income
        ->and(bal($this->bank->account->code))->toBe(-10_000_000 + 3_000_000);
});

/* ── Loan received (payable) ─────────────────────────────────────────────── */

it('pays an installment on a loan we received: interest is a COST, fee and penalty are expenses', function () {
    $loan = $this->loans->receive($this->borrower, 20_000_000, $this->bank->id, $this->now);

    $this->loans->payInstallment($loan, 2_200_000, 2_000_000, $this->bank->id, $this->now, 50_000, 30_000);

    expect($this->loans->remainingPrincipal($loan->fresh()))->toBe(18_000_000)
        ->and(bal('6300'))->toBe(120_000)  // interest = 2,200,000 − 2,000,000 − 50,000 − 30,000
        ->and(bal('6350'))->toBe(50_000)   // کارمزد بانکی
        ->and(bal('6370'))->toBe(30_000);  // جریمه دیرکرد
});

it('refuses to pay an installment on a loan running the other way', function () {
    $loan = $this->loans->give($this->borrower, 5_000_000, $this->bank->id, $this->now);

    // Paying an installment of a loan we GAVE is not a thing that can happen: the
    // borrower repays us. Getting this wrong would credit the bank instead of debiting it.
    expect(fn () => $this->loans->payInstallment($loan, 1_000_000, 1_000_000, $this->bank->id, $this->now))
        ->toThrow(InvalidArgumentException::class);
});

/* ── The schedule ────────────────────────────────────────────────────────── */

it('builds a schedule whose parts sum back to exactly the principal and interest', function () {
    // 10,000,000 over 3 installments does not divide evenly — and the rounding must not
    // leave a few Toman that can never be repaid, keeping the loan open forever.
    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 10_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now,
        'interest_method' => InterestMethod::Fixed,
        'interest_amount' => 1_000_000,
        'installment_count' => 3,
    ]);

    $schedule = $loan->installments;

    expect($schedule)->toHaveCount(3)
        ->and($schedule->sum('principal_part'))->toBe(10_000_000)
        ->and($schedule->sum('interest_part'))->toBe(1_000_000)
        ->and($schedule->sum('amount'))->toBe(11_000_000)
        // The remainder lands on the last row, never spread around.
        ->and($schedule->last()->principal_part)->toBe(3_333_334)
        ->and($loan->maturity_date->toDateString())->toBe($this->now->copy()->addMonthsNoOverflow(3)->toDateString());
});

it('computes flat annual interest over the term', function () {
    // 12,000,000 at 20% for 12 months = 2,400,000.
    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 12_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now,
        'interest_method' => InterestMethod::Flat,
        'interest_rate' => 20,
        'installment_count' => 12,
    ]);

    expect($loan->interest_amount)->toBe(2_400_000)
        ->and($loan->installments->first()->interest_part)->toBe(200_000);
});

it('closes the loan when the last of the principal is repaid', function () {
    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 3_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now,
        'installment_count' => 3,
    ]);

    foreach ($loan->installments as $installment) {
        $this->loans->payInstallment($loan->fresh(), 1_000_000, 1_000_000, $this->bank->id, $this->now, 0, 0, $installment);
    }

    expect($loan->fresh()->status)->toBe(LoanStatus::Paid)
        ->and($this->loans->remainingPrincipal($loan->fresh()))->toBe(0)
        ->and(bal('2200'))->toBe(0);
});

/* ── The guards that keep the ledger honest ──────────────────────────────── */

it('refuses to repay more principal than is outstanding', function () {
    $loan = $this->loans->receive($this->borrower, 1_000_000, $this->bank->id, $this->now);

    // Overpaying does not close the loan — it drives 2200 past zero and turns a settled
    // debt into a phantom balance running the other way.
    expect(fn () => $this->loans->payInstallment($loan, 1_500_000, 1_500_000, $this->bank->id, $this->now))
        ->toThrow(InvalidArgumentException::class);

    expect($this->loans->remainingPrincipal($loan->fresh()))->toBe(1_000_000);
});

it('refuses to pay the same installment twice', function () {
    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 2_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now,
        'installment_count' => 2,
    ]);

    $first = $loan->installments->first();
    $this->loans->payInstallment($loan, 1_000_000, 1_000_000, $this->bank->id, $this->now, 0, 0, $first);

    expect(fn () => $this->loans->payInstallment($loan->fresh(), 1_000_000, 1_000_000, $this->bank->id, $this->now, 0, 0, $first->fresh()))
        ->toThrow(OperationStateException::class);

    expect($this->loans->remainingPrincipal($loan->fresh()))->toBe(1_000_000);
});

it('freezes the financial terms once the loan is in the ledger', function () {
    $loan = $this->loans->receive($this->borrower, 5_000_000, $this->bank->id, $this->now);

    // Editing the principal of a posted loan would leave the journal entry saying one
    // thing and the contract another, with nothing to reconcile them.
    expect(fn () => $loan->update(['principal' => 9_000_000]))->toThrow(OperationStateException::class);

    // The rejected value is still dirty on the in-memory model, so reload before going on.
    $loan = $loan->fresh();

    // But a note is not a financial term — freezing the contract must not freeze the file.
    $loan->update(['notes' => 'یادداشت جدید']);

    expect($loan->fresh()->principal)->toBe(5_000_000)
        ->and($loan->fresh()->notes)->toBe('یادداشت جدید');
});

it('will not post a loan twice, however many times it is activated', function () {
    $loan = $this->loans->receive($this->borrower, 5_000_000, $this->bank->id, $this->now);

    expect(fn () => $this->loans->activate($loan->fresh(), null))->toThrow(OperationStateException::class);

    expect(JournalEntry::where('idempotency_key', "loan:{$loan->uuid}")->count())->toBe(1)
        ->and(bal('2200'))->toBe(-5_000_000);
});

/* ── Approval, cancellation and reversal ─────────────────────────────────── */

it('parks a loan above the approval threshold and posts nothing until a second person approves', function () {
    Setting::set(OperationPolicy::APPROVAL_THRESHOLD, 10_000_000);

    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 20_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now,
        'created_by' => $this->admin->id,
    ]);

    expect($loan->status)->toBe(LoanStatus::PendingApproval)
        ->and($loan->journal_entry_id)->toBeNull()
        ->and(bal('2200'))->toBe(0);

    // The creator can never be the approver, however senior — that is the whole control.
    expect(fn () => $this->loans->approve($loan, $this->admin))->toThrow(OperationStateException::class);

    $this->loans->approve($loan->fresh(), $this->other);

    expect($loan->fresh()->status)->toBe(LoanStatus::Active)
        ->and(bal('2200'))->toBe(-20_000_000);
});

it('cancels a pending loan without touching the ledger', function () {
    Setting::set(OperationPolicy::APPROVAL_THRESHOLD, 1_000_000);

    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 5_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now,
        'created_by' => $this->admin->id,
    ]);

    $this->loans->cancel($loan, 'قرارداد منتفی شد', $this->other);

    expect($loan->fresh()->status)->toBe(LoanStatus::Cancelled)
        ->and(JournalEntry::count())->toBe(0);
});

it('reverses a disbursement with an opposing entry, leaving the original intact', function () {
    $loan = $this->loans->receive($this->borrower, 8_000_000, $this->bank->id, $this->now);
    $original = $loan->journalEntry;
    $originalLines = $original->lines->map->only(['account_id', 'debit', 'credit'])->toArray();

    $this->loans->reverse($loan->fresh(), 'وام اشتباه ثبت شده بود', $this->admin);

    $original->refresh()->load('lines');

    expect($loan->fresh()->status)->toBe(LoanStatus::Reversed)
        // The original is byte-for-byte what it was: the fix sits on top of it.
        ->and($original->lines->map->only(['account_id', 'debit', 'credit'])->toArray())->toBe($originalLines)
        ->and(bal('2200'))->toBe(0)
        ->and(bal($this->bank->account->code))->toBe(0)
        // …and the outstanding principal follows the ledger, not the schedule.
        ->and($this->loans->remainingPrincipal($loan->fresh()))->toBe(0);
});

it('refuses to reverse a loan that has already been repaid in part', function () {
    $loan = $this->loans->receive($this->borrower, 5_000_000, $this->bank->id, $this->now);
    $this->loans->payInstallment($loan, 1_000_000, 1_000_000, $this->bank->id, $this->now);

    // Unwinding the disbursement while leaving the repayment in place would leave the
    // ledger holding a payment against a loan that was never made.
    expect(fn () => $this->loans->reverse($loan->fresh(), 'اشتباه بود', $this->admin))
        ->toThrow(OperationStateException::class);

    expect($loan->fresh()->status)->toBe(LoanStatus::Active);
});

it('un-pays an installment and lets it be paid again with a fresh key', function () {
    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 4_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now,
        'installment_count' => 2,
    ]);

    $first = $loan->installments->first();
    $this->loans->payInstallment($loan, 2_000_000, 2_000_000, $this->bank->id, $this->now, 0, 0, $first);

    expect($this->loans->remainingPrincipal($loan->fresh()))->toBe(2_000_000);

    $this->loans->reverseInstallment($first->fresh(), 'پرداخت اشتباه ثبت شد', $this->admin);

    // The money came back, so the installment is owed again — not parked in a status that
    // hides a real obligation behind a word.
    expect($first->fresh()->status)->toBe(LoanInstallment::PENDING)
        ->and($first->fresh()->paid_amount)->toBe(0)
        ->and($first->fresh()->reversal_entry_id)->not->toBeNull()
        ->and($this->loans->remainingPrincipal($loan->fresh()))->toBe(4_000_000)
        ->and($loan->fresh()->status)->toBe(LoanStatus::Active);

    // And paying it again really posts — the second attempt must not collide with the
    // idempotency key of the payment we just reversed and silently post nothing.
    $this->loans->payInstallment($loan->fresh(), 2_000_000, 2_000_000, $this->bank->id, $this->now, 0, 0, $first->fresh());

    expect($this->loans->remainingPrincipal($loan->fresh()))->toBe(2_000_000)
        ->and($first->fresh()->status)->toBe(LoanInstallment::PAID);
});

/* ── Overdue is derived and never touches the ledger ──────────────────────── */

it('marks installments overdue without moving a single balance', function () {
    $loan = $this->loans->create([
        'party' => $this->borrower,
        'direction' => LoanDirection::Payable,
        'principal' => 3_000_000,
        'bank_account_id' => $this->bank->id,
        'received_at' => $this->now->copy()->subMonths(6),
        'installment_count' => 3,
    ]);

    $before = JournalLine::selectRaw('SUM(debit) as d, SUM(credit) as c')->first();

    $this->artisan('loans:refresh-overdue')->assertSuccessful();

    $after = JournalLine::selectRaw('SUM(debit) as d, SUM(credit) as c')->first();

    // Being late does not make us owe more. If this ever posts an entry, it has invented
    // a liability out of a calendar date.
    expect($loan->fresh()->status)->toBe(LoanStatus::Overdue)
        ->and($loan->fresh()->installments->every(fn ($i) => $i->status === LoanInstallment::OVERDUE))->toBeTrue()
        ->and((int) $after->d)->toBe((int) $before->d)
        ->and((int) $after->c)->toBe((int) $before->c);
});

it('keeps the whole ledger balanced across every loan operation', function () {
    $given = $this->loans->give($this->borrower, 6_000_000, $this->bank->id, $this->now);
    $received = $this->loans->receive($this->borrower, 9_000_000, $this->bank->id, $this->now);

    $this->loans->receiveInstallment($given, 1_200_000, 1_000_000, $this->bank->id, $this->now, 100_000, 50_000);
    $this->loans->payInstallment($received, 1_500_000, 1_200_000, $this->bank->id, $this->now, 40_000, 10_000);

    $sums = JournalLine::selectRaw('SUM(debit) as d, SUM(credit) as c')->first();

    expect((int) $sums->d - (int) $sums->c)->toBe(0)
        ->and(JournalEntry::has('lines', '<', 2)->count())->toBe(0)
        ->and($this->loans->remainingPrincipal($given->fresh()))->toBe(5_000_000)
        ->and($this->loans->remainingPrincipal($received->fresh()))->toBe(7_800_000);
});

it('keeps two loans for the same party apart', function () {
    // A party-level balance would report 15,000,000 for both. The remaining principal has
    // to be scoped to the loan, or a borrower with two loans has two wrong numbers.
    $a = $this->loans->receive($this->borrower, 10_000_000, $this->bank->id, $this->now);
    $b = $this->loans->receive($this->borrower, 5_000_000, $this->bank->id, $this->now);

    $this->loans->payInstallment($a, 4_000_000, 4_000_000, $this->bank->id, $this->now);

    expect($this->loans->remainingPrincipal($a->fresh()))->toBe(6_000_000)
        ->and($this->loans->remainingPrincipal($b->fresh()))->toBe(5_000_000)
        ->and(bal('2200'))->toBe(-11_000_000);
});
