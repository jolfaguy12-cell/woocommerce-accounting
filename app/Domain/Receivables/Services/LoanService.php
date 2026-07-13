<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Loan;
use App\Domain\Receivables\Models\LoanInstallment;
use App\Domain\Receivables\Support\InterestMethod;
use App\Domain\Receivables\Support\LoanDirection;
use App\Domain\Receivables\Support\LoanStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Loans, in both directions, with their repayment schedules.
 *
 * The one rule that shapes everything here: **no calculated balance is ever stored.**
 * `remainingPrincipal()` is a query against the journal lines this loan is the source
 * of — not a column that gets decremented on each payment. A stored remaining-balance
 * column is a second copy of a number the ledger already owns, and the two agree right
 * up until the first crash between the two writes; after that nobody can say which one
 * is real. The schedule records what was AGREED, the ledger records what HAPPENED, and
 * wherever they could disagree, the ledger wins.
 *
 * The existing public signatures (`receive`, `payInstallment`) are unchanged — they
 * post exactly what they always posted, now through the general path.
 */
class LoanService
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly OperationPolicy $policy,
    ) {}

    /* ── Creating the contract ─────────────────────────────────────────────── */

    /** A loan we RECEIVED — «وام دریافتی». Signature preserved from the original service. */
    public function receive(Party $lender, int $principal, int $bankAccountId, Carbon $receivedAt): Loan
    {
        return $this->create([
            'party' => $lender,
            'direction' => LoanDirection::Payable,
            'principal' => $principal,
            'bank_account_id' => $bankAccountId,
            'received_at' => $receivedAt,
        ]);
    }

    /**
     * A loan we GAVE — «وام پرداختی». The mirror image: our money leaves, and what we
     * hold in its place is their obligation to return it (1600, an asset).
     */
    public function give(Party $borrower, int $principal, int $bankAccountId, Carbon $givenAt): Loan
    {
        return $this->create([
            'party' => $borrower,
            'direction' => LoanDirection::Receivable,
            'principal' => $principal,
            'bank_account_id' => $bankAccountId,
            'received_at' => $givenAt,
        ]);
    }

    /**
     * $data: party, direction, principal, bank_account_id, received_at
     *        [maturity_date, interest_method, interest_rate, interest_amount,
     *         installment_count, reference, notes, created_by]
     *
     * $requireApproval = false is for a caller that has ALREADY passed an approval gate
     * of its own: a partner loan approved as a partner operation must not then sit
     * waiting for a second, identical approval of the very same money.
     */
    public function create(array $data, bool $requireApproval = true): Loan
    {
        /** @var Party $party */
        $party = $data['party'];
        $direction = $data['direction'] instanceof LoanDirection
            ? $data['direction']
            : LoanDirection::from($data['direction']);
        $principal = (int) $data['principal'];
        $method = $this->method($data['interest_method'] ?? null);

        $bankAccount = BankAccount::with('account')->findOrFail($data['bank_account_id']);
        $start = $this->date($data['received_at']);
        $count = max(0, (int) ($data['installment_count'] ?? 0));

        $this->assertCreatable($principal, $bankAccount, $method, $data);

        return DB::transaction(function () use ($data, $party, $direction, $principal, $method, $bankAccount, $start, $count, $requireApproval) {
            $totalInterest = $method->totalInterest(
                $principal,
                isset($data['interest_rate']) ? (float) $data['interest_rate'] : null,
                isset($data['interest_amount']) ? (int) $data['interest_amount'] : null,
                $count,
            );

            $loan = Loan::create([
                'uuid' => (string) Str::uuid(),
                // Explicit, never left to the column default: the lifecycle asks for the
                // status immediately, and a DB default is not on the in-memory model.
                'status' => LoanStatus::Draft->value,
                'party_id' => $party->id,
                'direction' => $direction,
                'principal' => $principal,
                'bank_account_id' => $bankAccount->id,
                'received_at' => $start->toDateString(),
                'maturity_date' => isset($data['maturity_date'])
                    ? $this->date($data['maturity_date'])->toDateString()
                    : ($count > 0 ? $start->copy()->addMonthsNoOverflow($count)->toDateString() : null),
                'interest_method' => $method,
                'interest_rate' => $data['interest_rate'] ?? null,
                'interest_amount' => $totalInterest,
                'installment_count' => $count,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $this->generateSchedule($loan, $totalInterest);

            if ($requireApproval && $this->policy->requiresApproval($principal)) {
                $loan->forceFill([
                    'status' => LoanStatus::PendingApproval->value,
                    'submitted_by' => $data['created_by'] ?? null,
                    'submitted_at' => now(),
                ])->save();

                return $loan->fresh();
            }

            return $this->activate($loan, $data['created_by'] ?? null);
        });
    }

    /**
     * «برنامه اقساط» — the schedule.
     *
     * Integer Toman means the split will not divide evenly, and the rounding has to land
     * SOMEWHERE. It lands on the last installment, so the parts always sum back to exactly
     * the principal and exactly the total interest. Rounding each row independently instead
     * leaves a schedule whose total is a few Toman away from the loan — a difference that
     * can never be repaid, and that keeps the loan open forever.
     */
    public function generateSchedule(Loan $loan, ?int $totalInterest = null): void
    {
        $count = (int) $loan->installment_count;

        if ($count < 1) {
            return; // A single bullet repayment: no schedule; it is settled when it is settled.
        }

        $totalInterest ??= (int) $loan->interest_amount;

        $principalEach = intdiv((int) $loan->principal, $count);
        $interestEach = intdiv($totalInterest, $count);

        $principalRemainder = (int) $loan->principal - ($principalEach * $count);
        $interestRemainder = $totalInterest - ($interestEach * $count);

        $start = Carbon::parse($loan->received_at, JalaliPeriod::TIMEZONE);

        for ($i = 1; $i <= $count; $i++) {
            $isLast = $i === $count;

            $principalPart = $principalEach + ($isLast ? $principalRemainder : 0);
            $interestPart = $interestEach + ($isLast ? $interestRemainder : 0);

            $loan->installments()->create([
                'sequence' => $i,
                'due_date' => $start->copy()->addMonthsNoOverflow($i)->toDateString(),
                'amount' => $principalPart + $interestPart,
                'principal_part' => $principalPart,
                'interest_part' => $interestPart,
                'fee_part' => 0,
                'penalty_part' => 0,
                'status' => LoanInstallment::PENDING,
            ]);
        }
    }

    /* ── Lifecycle ─────────────────────────────────────────────────────────── */

    /** Post the disbursement. The one place a loan becomes real. */
    public function activate(Loan $loan, ?int $by): Loan
    {
        if ($loan->status->isDisbursed()) {
            throw new OperationStateException('This loan has already been disbursed.');
        }

        if ($loan->status === LoanStatus::Cancelled) {
            throw new OperationStateException('A cancelled loan cannot be disbursed.');
        }

        return DB::transaction(function () use ($loan, $by) {
            $loan->loadMissing(['bankAccount', 'party']);

            $bankLedger = $loan->bankAccount->account_id;
            $principalAccount = $loan->direction->principalAccount();

            $lines = $loan->isReceivable()
                // Loan given: our money leaves, their obligation to us appears.
                ? [
                    ['account' => $principalAccount, 'debit' => $loan->principal, 'party_id' => $loan->party_id, 'memo' => 'اصل وام پرداختی'],
                    ['account' => $bankLedger, 'credit' => $loan->principal, 'memo' => "وام پرداختی به {$loan->party->name}"],
                ]
                // Loan received: their money arrives, our obligation to them appears.
                : [
                    ['account' => $bankLedger, 'debit' => $loan->principal, 'memo' => "وام دریافتی از {$loan->party->name}"],
                    ['account' => $principalAccount, 'credit' => $loan->principal, 'party_id' => $loan->party_id, 'memo' => 'اصل وام دریافتی'],
                ];

            $entry = $this->poster->post([
                'entry_date' => Carbon::parse($loan->received_at, JalaliPeriod::TIMEZONE),
                'description' => "{$loan->direction->label()} — {$loan->party->name}",
                'idempotency_key' => "loan:{$loan->uuid}",
                'source' => $loan,
                'created_by' => $loan->created_by ?? $by,
            ], $lines);

            $loan->forceFill([
                'status' => LoanStatus::Active->value,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ])->save();

            return $loan->fresh();
        });
    }

    public function approve(Loan $loan, User $approver): Loan
    {
        if ($loan->status !== LoanStatus::PendingApproval) {
            throw new OperationStateException("Only a loan awaiting approval can be approved; this one is [{$loan->status->value}].");
        }

        if (! $this->policy->canApprove($approver, $loan)) {
            throw new OperationStateException('This user may not approve this loan (either they created it, or their role does not permit approval).');
        }

        $loan->forceFill(['approved_by' => $approver->id, 'approved_at' => now()])->save();

        return $this->activate($loan, $approver->id);
    }

    /** Abandon a loan that never reached the ledger. Nothing posted, so nothing to unwind. */
    public function cancel(Loan $loan, string $reason, User $by): Loan
    {
        if (! $loan->status->isCancellable()) {
            throw new OperationStateException(
                "A loan that is [{$loan->status->value}] cannot be cancelled; a disbursed loan is reversed instead."
            );
        }

        $loan->forceFill([
            'status' => LoanStatus::Cancelled->value,
            'cancel_reason' => $reason,
            'cancelled_by' => $by->id,
            'cancelled_at' => now(),
        ])->save();

        return $loan->fresh();
    }

    /**
     * Reverse the disbursement. The loan, its schedule and its original entry all survive
     * exactly as they were; an opposing entry cancels the money out.
     *
     * Refused once anything has been repaid: unwinding the disbursement while leaving the
     * repayments in place would leave the ledger holding payments against a loan that, as
     * far as the books are concerned, was never made. Reverse the installments first —
     * deliberately, one at a time, each with its own reason.
     */
    public function reverse(Loan $loan, string $reason, User $by): Loan
    {
        if (! $loan->status->isDisbursed() || $loan->status === LoanStatus::Reversed) {
            throw new OperationStateException("Only a disbursed loan can be reversed; this one is [{$loan->status->value}].");
        }

        if (! $this->policy->canReverse($by)) {
            throw new OperationStateException('This user may not reverse loans.');
        }

        if ($loan->installments()->where('status', LoanInstallment::PAID)->exists()) {
            throw new OperationStateException(
                'این وام اقساط پرداخت‌شده دارد. ابتدا اقساط پرداخت‌شده را برگشت بزنید، سپس خودِ وام را.'
            );
        }

        return DB::transaction(function () use ($loan, $reason, $by) {
            $reversal = $this->poster->reverse($loan->journalEntry, $reason, $by->id);

            $loan->forceFill([
                'status' => LoanStatus::Reversed->value,
                'reversal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
            ])->save();

            return $loan->fresh();
        });
    }

    /**
     * Mark a loan reversed WITHOUT posting anything — for when the owning operation has
     * already reversed the journal entry the two of them share (a partner loan). Posting
     * a second reversal of the same entry would hand the money back twice.
     */
    public function markReversedByOwner(Loan $loan, JournalEntry $reversal, string $reason, User $by): Loan
    {
        $loan->forceFill([
            'status' => LoanStatus::Reversed->value,
            'reversal_entry_id' => $reversal->id,
            'reversal_reason' => $reason,
            'reversed_by' => $by->id,
            'reversed_at' => now(),
        ])->save();

        return $loan->fresh();
    }

    /* ── Installments ──────────────────────────────────────────────────────── */

    /**
     * Pay one installment of a loan we RECEIVED. Signature preserved — the trailing
     * arguments are new and optional, so every existing caller behaves identically.
     *
     * Interest is whatever remains after principal, fee and penalty, which is exactly how
     * the original version behaved.
     */
    public function payInstallment(
        Loan $loan,
        int $amount,
        int $principalPart,
        int $bankAccountId,
        Carbon $paidAt,
        int $feePart = 0,
        int $penaltyPart = 0,
        ?LoanInstallment $installment = null,
        ?int $by = null,
    ): LoanInstallment {
        $this->assertDirection($loan, LoanDirection::Payable, 'پرداخت قسط');

        return $this->settle($loan, $amount, $principalPart, $feePart, $penaltyPart, $bankAccountId, $paidAt, $installment, $by);
    }

    /** Receive one installment of a loan we GAVE. */
    public function receiveInstallment(
        Loan $loan,
        int $amount,
        int $principalPart,
        int $bankAccountId,
        Carbon $paidAt,
        int $feePart = 0,
        int $penaltyPart = 0,
        ?LoanInstallment $installment = null,
        ?int $by = null,
    ): LoanInstallment {
        $this->assertDirection($loan, LoanDirection::Receivable, 'دریافت قسط');

        return $this->settle($loan, $amount, $principalPart, $feePart, $penaltyPart, $bankAccountId, $paidAt, $installment, $by);
    }

    /**
     * The single posting path for an installment, in either direction.
     *
     * Payable loan: we hand over the total, and each part is debited to the account it
     * belongs to — principal reduces the loan, interest is a finance cost, fees and
     * penalties are their own expenses.
     *
     * Receivable loan: the total arrives, principal reduces what they owe us, and the
     * interest, fee and penalty are INCOME. The same four parts, mirrored.
     */
    private function settle(
        Loan $loan,
        int $amount,
        int $principalPart,
        int $feePart,
        int $penaltyPart,
        int $bankAccountId,
        Carbon $paidAt,
        ?LoanInstallment $installment,
        ?int $by,
    ): LoanInstallment {
        $interestPart = $amount - $principalPart - $feePart - $penaltyPart;

        $this->assertSettleable($loan, $installment, $amount, $principalPart, $interestPart, $feePart, $penaltyPart);

        $bankLedger = BankAccount::findOrFail($bankAccountId)->account_id;

        return DB::transaction(function () use ($loan, $amount, $principalPart, $interestPart, $feePart, $penaltyPart, $bankLedger, $paidAt, $installment, $by) {
            // An unscheduled repayment (an early settlement, or the legacy call that
            // passes no installment) still becomes a real schedule row: the schedule is
            // the record of what was repaid, and a repayment with no row is invisible in it.
            $installment ??= $loan->installments()->create([
                'sequence' => (int) $loan->installments()->max('sequence') + 1,
                'amount' => $amount,
                'principal_part' => $principalPart,
                'interest_part' => $interestPart,
                'fee_part' => $feePart,
                'penalty_part' => $penaltyPart,
                'due_date' => $paidAt->toDateString(),
                'status' => LoanInstallment::PENDING,
            ]);

            $attempt = (int) $installment->payment_attempts;
            $direction = $loan->direction;
            $lines = [];

            if ($loan->isReceivable()) {
                $lines[] = ['account' => $bankLedger, 'debit' => $amount, 'memo' => "دریافت قسط {$installment->sequence}"];
                $lines[] = ['account' => $direction->principalAccount(), 'credit' => $principalPart,
                    'party_id' => $loan->party_id, 'memo' => 'اصل وام'];

                if ($interestPart > 0) {
                    $lines[] = ['account' => $direction->interestAccount(), 'credit' => $interestPart, 'memo' => 'سود'];
                }
                if ($feePart > 0) {
                    $lines[] = ['account' => $direction->feeAccount(), 'credit' => $feePart, 'memo' => 'کارمزد'];
                }
                if ($penaltyPart > 0) {
                    $lines[] = ['account' => $direction->penaltyAccount(), 'credit' => $penaltyPart, 'memo' => 'جریمه دیرکرد'];
                }
            } else {
                $lines[] = ['account' => $direction->principalAccount(), 'debit' => $principalPart,
                    'party_id' => $loan->party_id, 'memo' => 'اصل وام'];

                if ($interestPart > 0) {
                    $lines[] = ['account' => $direction->interestAccount(), 'debit' => $interestPart, 'memo' => 'سود'];
                }
                if ($feePart > 0) {
                    $lines[] = ['account' => $direction->feeAccount(), 'debit' => $feePart, 'memo' => 'کارمزد'];
                }
                if ($penaltyPart > 0) {
                    $lines[] = ['account' => $direction->penaltyAccount(), 'debit' => $penaltyPart, 'memo' => 'جریمه دیرکرد'];
                }

                $lines[] = ['account' => $bankLedger, 'credit' => $amount, 'memo' => "پرداخت قسط {$installment->sequence}"];
            }

            $entry = $this->poster->post([
                'entry_date' => $paidAt,
                'description' => "قسط {$installment->sequence} — {$loan->direction->label()} {$loan->party->name}",
                'idempotency_key' => "loan_installment:{$loan->uuid}:{$installment->id}:{$attempt}",
                'source' => $loan,
                'created_by' => $by,
            ], $lines);

            $installment->forceFill([
                'amount' => $amount,
                'principal_part' => $principalPart,
                'interest_part' => $interestPart,
                'fee_part' => $feePart,
                'penalty_part' => $penaltyPart,
                'paid_amount' => $amount,
                'paid_at' => $paidAt->toDateString(),
                'paid_by' => $by,
                'status' => LoanInstallment::PAID,
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->refreshSettlement($loan);

            return $installment->fresh();
        });
    }

    /**
     * Un-pay an installment. The original entry stays; an opposing one cancels it out, and
     * the installment goes back to being owed — because it IS owed again.
     */
    public function reverseInstallment(LoanInstallment $installment, string $reason, User $by): LoanInstallment
    {
        if (! $installment->isPaid() || ! $installment->journalEntry) {
            throw new OperationStateException('Only a paid installment can be reversed.');
        }

        if (! $this->policy->canReverse($by)) {
            throw new OperationStateException('This user may not reverse loan installments.');
        }

        return DB::transaction(function () use ($installment, $reason, $by) {
            $reversal = $this->poster->reverse($installment->journalEntry, $reason, $by->id);

            $installment->forceFill([
                'status' => LoanInstallment::PENDING,
                'paid_amount' => 0,
                'paid_at' => null,
                'reversal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
                // The NEXT payment of this installment needs an idempotency key of its
                // own, or it would collide with the one just reversed and post nothing
                // at all — leaving the installment marked paid with no entry behind it.
                'payment_attempts' => (int) $installment->payment_attempts + 1,
            ])->save();

            $this->refreshSettlement($installment->loan);

            return $installment->fresh();
        });
    }

    /* ── Derived figures — every one of these is a query, never a column ────── */

    /**
     * «مانده اصل وام» — outstanding principal, read straight out of the ledger.
     *
     * Scoped to the entries this loan is the source of (and any that reverse them), so a
     * party holding three loans gets three correct answers instead of one aggregate that
     * describes none of them.
     */
    public function remainingPrincipal(Loan $loan): int
    {
        $account = $loan->direction->principalAccount()->account();

        $sums = JournalLine::whereIn('journal_entry_id', $this->entryIds($loan))
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $debit = (int) $sums->debit;
        $credit = (int) $sums->credit;

        // Receivable principal is an asset (debit-natural); payable principal is a liability.
        return $loan->isReceivable() ? $debit - $credit : $credit - $debit;
    }

    /**
     * What has actually been repaid, split by part — «اصل وام»، «سود»، «کارمزد»،
     * «جریمه دیرکرد». Read from the installments, each of which is backed by an entry.
     *
     * @return array{principal: int, interest: int, fee: int, penalty: int, total: int}
     */
    public function paidTotals(Loan $loan): array
    {
        $paid = $loan->installments()->where('status', LoanInstallment::PAID)->get();

        return [
            'principal' => (int) $paid->sum('principal_part'),
            'interest' => (int) $paid->sum('interest_part'),
            'fee' => (int) $paid->sum('fee_part'),
            'penalty' => (int) $paid->sum('penalty_part'),
            'total' => (int) $paid->sum('paid_amount'),
        ];
    }

    /**
     * Overdue is a DERIVED state and it must never move a balance: an installment falling
     * due changes nothing in the accounts — the money was already owed, and being late
     * about it does not make us owe more. This writes status columns and nothing else.
     *
     * @return array{installments: int, loans: int}
     */
    public function refreshOverdue(): array
    {
        $today = Carbon::now(JalaliPeriod::TIMEZONE)->startOfDay()->toDateString();

        $installments = LoanInstallment::where('status', LoanInstallment::PENDING)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->update(['status' => LoanInstallment::OVERDUE]);

        // …and back out of it again if the date moves. A status that can only travel one
        // way is a status that slowly stops being true.
        LoanInstallment::where('status', LoanInstallment::OVERDUE)
            ->whereDate('due_date', '>=', $today)
            ->update(['status' => LoanInstallment::PENDING]);

        $overdueLoanIds = LoanInstallment::where('status', LoanInstallment::OVERDUE)
            ->distinct()->pluck('loan_id');

        $loans = Loan::whereIn('id', $overdueLoanIds)
            ->where('status', LoanStatus::Active->value)
            ->update(['status' => LoanStatus::Overdue->value]);

        Loan::whereNotIn('id', $overdueLoanIds)
            ->where('status', LoanStatus::Overdue->value)
            ->update(['status' => LoanStatus::Active->value]);

        return ['installments' => $installments, 'loans' => $loans];
    }

    /* ── Guards ────────────────────────────────────────────────────────────── */

    private function assertCreatable(int $principal, BankAccount $bankAccount, InterestMethod $method, array $data): void
    {
        if ($principal < 1) {
            throw new InvalidArgumentException('مبلغ اصل وام باید بزرگ‌تر از صفر باشد.');
        }

        if (! $bankAccount->is_active) {
            throw new InvalidArgumentException("حساب «{$bankAccount->name}» غیرفعال است.");
        }

        if ($method->needsRate() && blank($data['interest_rate'] ?? null)) {
            throw new InvalidArgumentException('برای روش «نرخ سالانه ثابت»، نرخ سود الزامی است.');
        }

        if ($method->needsAmount() && blank($data['interest_amount'] ?? null)) {
            throw new InvalidArgumentException('برای روش «مبلغ کل سود»، مبلغ سود الزامی است.');
        }

        if ($method->needsRate() && (int) ($data['installment_count'] ?? 0) < 1) {
            throw new InvalidArgumentException('برای محاسبهٔ سود با نرخ سالانه، تعداد اقساط الزامی است.');
        }
    }

    private function assertDirection(Loan $loan, LoanDirection $expected, string $action): void
    {
        if ($loan->direction !== $expected) {
            throw new InvalidArgumentException("«{$action}» برای «{$loan->direction->label()}» معنا ندارد.");
        }
    }

    private function assertSettleable(
        Loan $loan,
        ?LoanInstallment $installment,
        int $amount,
        int $principalPart,
        int $interestPart,
        int $feePart,
        int $penaltyPart,
    ): void {
        if (! $loan->status->isRepaying()) {
            throw new OperationStateException(
                "قسط فقط برای وام جاری قابل ثبت است؛ وضعیت این وام [{$loan->status->value}] است."
            );
        }

        foreach (['اصل وام' => $principalPart, 'سود' => $interestPart, 'کارمزد' => $feePart, 'جریمه دیرکرد' => $penaltyPart] as $part => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("جزء «{$part}» قسط نمی‌تواند منفی باشد.");
            }
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('مبلغ قسط باید بزرگ‌تر از صفر باشد.');
        }

        // An installment posts exactly once. Without this, a double-submitted form would
        // post a second entry and pay the same installment twice.
        if ($installment && $installment->isPaid()) {
            throw new OperationStateException("قسط شماره {$installment->sequence} قبلاً پرداخت شده است.");
        }

        if ($installment && (int) $installment->loan_id !== (int) $loan->id) {
            throw new InvalidArgumentException('این قسط متعلق به وام دیگری است.');
        }

        // The most important guard here. Repaying more principal than is outstanding does
        // not close the loan — it drives the loan account past zero and turns a settled
        // debt into a phantom balance running the other way.
        $remaining = $this->remainingPrincipal($loan);

        if ($principalPart > $remaining) {
            throw new InvalidArgumentException(
                'اصل قسط از مانده اصل وام بیشتر است: مانده '.number_format($remaining).' تومان.'
            );
        }
    }

    /* ── Internals ─────────────────────────────────────────────────────────── */

    /** Fully repaid → paid. Reversed back into debt → active again. Status only, no posting. */
    private function refreshSettlement(Loan $loan): void
    {
        $loan->refresh();

        if (! $loan->status->isRepaying() && $loan->status !== LoanStatus::Paid) {
            return;
        }

        $loan->forceFill([
            'status' => $this->remainingPrincipal($loan) <= 0
                ? LoanStatus::Paid->value
                : LoanStatus::Active->value,
        ])->save();
    }

    /**
     * Every journal entry belonging to this loan: the ones it is the source of, plus the
     * ones that reverse them.
     *
     * That second half is not a nicety. JournalPoster::reverse() does not copy the source
     * onto the reversing entry, so a source-only query would count the disbursement and
     * miss its reversal — and a reversed loan would report its full principal as still
     * outstanding, forever.
     */
    private function entryIds(Loan $loan)
    {
        $own = JournalEntry::where('source_type', $loan->getMorphClass())
            ->where('source_id', $loan->id)
            ->select('id');

        return JournalEntry::query()
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w
                    ->where('source_type', $loan->getMorphClass())
                    ->where('source_id', $loan->id))
                ->orWhereIn('reversal_of_entry_id', $own))
            ->select('id');
    }

    private function method(mixed $value): InterestMethod
    {
        if ($value instanceof InterestMethod) {
            return $value;
        }

        return blank($value) ? InterestMethod::None : InterestMethod::from($value);
    }

    private function date(mixed $value): Carbon
    {
        return $value instanceof Carbon ? $value : Carbon::parse($value, JalaliPeriod::TIMEZONE);
    }
}
