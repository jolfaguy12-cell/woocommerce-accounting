<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\NegativeBalanceException;
use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Accounting\Support\OperationStatus;
use App\Domain\Expenses\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The draft → pending_approval → posted → reversed lifecycle, written once.
 *
 * Both operation types are structurally identical — they differ only in the
 * journal lines they produce and the money they move out of an account. Those
 * two things are the abstract methods below; everything else (approval, the
 * single posting path, reversal, cancellation, the negative-balance guard) is
 * shared, so the two services cannot drift into two subtly different rulebooks.
 *
 * JournalPoster remains the ONLY way anything here reaches the ledger.
 */
abstract class FinancialOperationService
{
    public function __construct(
        protected readonly JournalPoster $poster,
        protected readonly OperationPolicy $policy,
    ) {}

    /** The journal lines this operation posts — the single definition of its accounting. */
    abstract protected function lines(Model $operation): array;

    abstract protected function description(Model $operation): string;

    abstract protected function idempotencyKey(Model $operation): string;

    abstract protected function entryDate(Model $operation): Carbon;

    /**
     * Money leaving each bank account when this posts: [bank_account_id => amount].
     * Drives the negative-balance guard; an operation that only brings money in
     * returns [].
     *
     * @return array<int, int>
     */
    abstract protected function outflows(Model $operation): array;

    /**
     * The final step of every create(): either park the operation for approval or
     * post it straight away. Concrete services build and validate their own row,
     * then hand it here — so the approval rule is applied in exactly one place
     * and no operation type can quietly skip it.
     */
    protected function finalizeCreation(Model $operation, ?int $by): Model
    {
        if ($this->requiresApproval($operation)) {
            $operation->forceFill([
                'status' => OperationStatus::PendingApproval->value,
                'submitted_by' => $by,
                'submitted_at' => now(),
            ])->save();

            return $operation->fresh();
        }

        return $this->post($operation, $by);
    }

    /**
     * Does this operation need a second pair of eyes before it can reach the ledger?
     *
     * By default: only above the configured amount threshold. A subclass may widen
     * it — some operations are high-risk at any amount, and the amount is the least
     * interesting thing about them (see AccountTransactionService and adjustments).
     */
    protected function requiresApproval(Model $operation): bool
    {
        return $this->policy->requiresApproval((int) $operation->amount);
    }

    /** Extra approval conditions beyond "is not the creator, has the role". */
    protected function assertApprovable(Model $operation, User $approver): void {}

    /** Extra reversal conditions. Default: anything posted may be reversed. */
    protected function assertReversible(Model $operation): void {}

    /**
     * Approve a pending operation and post it. The approver is never the creator
     * (OperationPolicy::canApprove), which is the entire value of the step.
     */
    public function approve(Model $operation, User $approver): Model
    {
        if (! $operation->isPendingApproval()) {
            throw new OperationStateException("Only an operation awaiting approval can be approved; this one is [{$operation->status}].");
        }

        if (! $this->policy->canApprove($approver, $operation)) {
            throw new OperationStateException('This user may not approve this operation (either they created it, or their role does not permit approval).');
        }

        $this->assertApprovable($operation, $approver);

        $operation->forceFill([
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        return $this->post($operation, $approver->id);
    }

    /**
     * The one place an operation becomes real. Idempotent through JournalPoster's
     * key, so a double submit re-attaches the same entry instead of posting twice.
     */
    public function post(Model $operation, ?int $by): Model
    {
        if ($operation->operationStatus()->isPosted()) {
            throw new OperationStateException('This operation is already posted.');
        }

        if ($operation->isCancelled()) {
            throw new OperationStateException('A cancelled operation cannot be posted.');
        }

        $this->assertBalancesAllowed($operation);

        return DB::transaction(function () use ($operation, $by) {
            $entry = $this->postEntry($operation, $by);

            $operation->forceFill([
                'status' => OperationStatus::Posted->value,
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ])->save();

            return $operation->fresh();
        });
    }

    /**
     * The journal entry this operation results in.
     *
     * Overridable because some operations do not OWN their entry: a partner loan is
     * a loan, and the loan's own service posts it. Such an operation returns that
     * same entry here and posts nothing of its own — one disbursement, one entry.
     * Posting a second, partner-shaped entry alongside the loan's would double the
     * money in the ledger while looking perfectly balanced.
     */
    protected function postEntry(Model $operation, ?int $by): JournalEntry
    {
        return $this->poster->post([
            'entry_date' => $this->entryDate($operation),
            'description' => $this->description($operation),
            'idempotency_key' => $this->idempotencyKey($operation),
            'source' => $operation,
            'created_by' => $operation->created_by ?? $by,
        ], $this->lines($operation));
    }

    /**
     * Reverse a posted operation: the original entry and its lines stay exactly
     * as they were, and an opposing entry cancels them out. History is added to,
     * never rewritten — the reason and the reverser are part of the record.
     */
    public function reverse(Model $operation, string $reason, User $by): Model
    {
        if (! $operation->isPosted()) {
            throw new OperationStateException("Only a posted operation can be reversed; this one is [{$operation->status}].");
        }

        if (! $this->policy->canReverse($by)) {
            throw new OperationStateException('This user may not reverse financial operations.');
        }

        $this->assertReversible($operation);

        return DB::transaction(function () use ($operation, $reason, $by) {
            $reversal = $this->poster->reverse($operation->journalEntry, $reason, $by->id);

            $operation->forceFill([
                'status' => OperationStatus::Reversed->value,
                'reversal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
            ])->save();

            $this->afterReversal($operation, $reversal);

            return $operation->fresh();
        });
    }

    /** Anything the operation owns that must follow it into the reversed state. */
    protected function afterReversal(Model $operation, JournalEntry $reversal): void {}

    /** Abandon an operation that never reached the ledger. Nothing to unwind — so nothing is posted. */
    public function cancel(Model $operation, string $reason, User $by): Model
    {
        if (! $operation->operationStatus()->isCancellable()) {
            throw new OperationStateException("An operation that is [{$operation->status}] cannot be cancelled; a posted operation is reversed instead.");
        }

        $operation->forceFill([
            'status' => OperationStatus::Cancelled->value,
            'cancel_reason' => $reason,
            'cancelled_by' => $by->id,
            'cancelled_at' => now(),
        ])->save();

        return $operation->fresh();
    }

    /**
     * The accounts this operation draws on that end up below zero.
     *
     * Evaluated against live balances, so it is only checked at post time, not at
     * create time: a pending operation moves no money, and the balance it has to
     * clear is the one on the day it is approved, not the day it was typed in.
     *
     * Once the operation is posted its outflow is already inside the balance —
     * subtracting it again would report a phantom overdraft of twice the amount.
     *
     * @return array<int, int> bank_account_id => the balance it lands on (negative only)
     */
    public function overdrafts(Model $operation): array
    {
        $alreadyInLedger = $operation->operationStatus()->isPosted();
        $overdrafts = [];

        foreach ($this->outflows($operation) as $bankAccountId => $amount) {
            $bankAccount = BankAccount::with('account')->find($bankAccountId);

            if (! $bankAccount) {
                continue;
            }

            $resulting = $bankAccount->account->balance() - ($alreadyInLedger ? 0 : (int) $amount);

            if ($resulting < 0) {
                $overdrafts[$bankAccountId] = $resulting;
            }
        }

        return $overdrafts;
    }

    private function assertBalancesAllowed(Model $operation): void
    {
        if ($this->policy->negativeBalanceMode() !== OperationPolicy::MODE_BLOCK) {
            return;
        }

        if ($this->overdrafts($operation) !== []) {
            throw new NegativeBalanceException('این عملیات موجودی حساب را منفی می‌کند و تنظیمات فعلی آن را اجازه نمی‌دهد.');
        }
    }
}
