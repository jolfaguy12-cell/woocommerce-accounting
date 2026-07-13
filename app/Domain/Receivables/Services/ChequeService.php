<?php

namespace App\Domain\Receivables\Services;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\AccountCode;
use App\Domain\Accounting\Support\JalaliPeriod;
use App\Domain\Accounting\Support\OperationPolicy;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Cheque;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cheques, in both directions.
 *
 * A cheque is a promise, and the ledger records it the day the promise is made rather
 * than the day it is kept. Registering one moves the balance out of the receivable (or
 * payable) and into 1250 (or 2100), where "owed on paper" sits apart from "owed" —
 * which is exactly what a business needs to see, because a customer who has handed over
 * a post-dated cheque has not paid, and a customer who has paid has not handed over a
 * cheque.
 *
 * Only CLEARING touches an actual bank account. And there is no cheque balance engine
 * here, nor may one ever be added: accounts 1250 and 2100 in the ledger ARE the cheque
 * balances. A second place to keep them would be a second place for them to be wrong.
 *
 * The original register/clear/bounce behaviour is preserved exactly; cancellation and
 * reversal are added on top.
 */
class ChequeService
{
    public function __construct(
        private readonly JournalPoster $poster,
        private readonly OperationPolicy $policy,
    ) {}

    /**
     * A customer's cheque, received against their receivable. Signature preserved; the
     * trailing $data is new and optional.
     *
     * @param  array<string, mixed>  $data  [bank_name, reference, notes, description, created_by]
     */
    public function registerReceivable(Party $party, int $amount, Carbon $dueDate, ?string $serial = null, array $data = []): Cheque
    {
        return $this->register(Cheque::RECEIVABLE, $party, $amount, $dueDate, $serial, $data, [
            ['account' => AccountCode::ChequesReceivable, 'debit' => $amount, 'memo' => "چک دریافتی از {$party->name}"],
            ['account' => AccountCode::AccountsReceivable, 'credit' => $amount, 'party_id' => $party->id, 'memo' => 'تسویه با چک'],
        ]);
    }

    /** Our cheque, handed to a supplier against what we owe them. */
    public function registerPayable(Party $party, int $amount, Carbon $dueDate, ?string $serial = null, array $data = []): Cheque
    {
        return $this->register(Cheque::PAYABLE, $party, $amount, $dueDate, $serial, $data, [
            ['account' => AccountCode::AccountsPayable, 'debit' => $amount, 'party_id' => $party->id, 'memo' => 'تسویه با چک'],
            ['account' => AccountCode::ChequesPayable, 'credit' => $amount, 'party_id' => $party->id, 'memo' => "چک پرداختی به {$party->name}"],
        ]);
    }

    /** The cheque was honoured: the promise becomes money. */
    public function clear(Cheque $cheque, int $bankAccountId, ?int $by = null): Cheque
    {
        $this->assertPending($cheque);

        $bank = BankAccount::findOrFail($bankAccountId);

        $lines = $cheque->isReceivable()
            ? [
                ['account' => $bank->account_id, 'debit' => $cheque->amount, 'memo' => "وصول چک {$cheque->serial}"],
                ['account' => AccountCode::ChequesReceivable, 'credit' => $cheque->amount, 'memo' => 'وصول چک دریافتی'],
            ]
            : [
                ['account' => AccountCode::ChequesPayable, 'debit' => $cheque->amount, 'party_id' => $cheque->party_id, 'memo' => 'پاس شدن چک پرداختی'],
                ['account' => $bank->account_id, 'credit' => $cheque->amount, 'memo' => "پرداخت چک {$cheque->serial}"],
            ];

        return $this->settle($cheque, Cheque::CLEARED, 'وصول چک', $lines, $bank->id, $by);
    }

    /**
     * The cheque was not honoured. The debt does not disappear — it goes back to where it
     * was before the cheque existed, which is the whole point of holding it in 1250/2100
     * rather than treating it as paid.
     */
    public function bounce(Cheque $cheque, ?int $by = null): Cheque
    {
        $this->assertPending($cheque);

        $lines = $cheque->isReceivable()
            ? [
                ['account' => AccountCode::AccountsReceivable, 'debit' => $cheque->amount, 'party_id' => $cheque->party_id, 'memo' => 'برگشت چک — بدهی به قوت خود باقی است'],
                ['account' => AccountCode::ChequesReceivable, 'credit' => $cheque->amount, 'memo' => "برگشت چک {$cheque->serial}"],
            ]
            : [
                ['account' => AccountCode::ChequesPayable, 'debit' => $cheque->amount, 'party_id' => $cheque->party_id, 'memo' => "برگشت چک {$cheque->serial}"],
                ['account' => AccountCode::AccountsPayable, 'credit' => $cheque->amount, 'party_id' => $cheque->party_id, 'memo' => 'برگشت چک — بدهی به قوت خود باقی است'],
            ];

        return $this->settle($cheque, Cheque::BOUNCED, 'برگشت چک', $lines, null, $by);
    }

    /**
     * The cheque should never have been registered — it was torn up, or entered twice.
     *
     * Only possible while it is still outstanding, and it does not erase anything: the
     * registration entry stays, and an opposing entry undoes it. The party's original
     * receivable/payable comes back, exactly as it was.
     */
    public function cancel(Cheque $cheque, string $reason, User $by): Cheque
    {
        $this->assertPending($cheque);

        if (! $this->policy->canReverse($by)) {
            throw new OperationStateException('This user may not cancel cheques.');
        }

        return DB::transaction(function () use ($cheque, $reason, $by) {
            $reversal = $this->poster->reverse($cheque->journalEntry, $reason, $by->id);

            $cheque->forceFill([
                'status' => Cheque::CANCELLED,
                'reversal_entry_id' => $reversal->id,
                'cancel_reason' => $reason,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
            ])->save();

            return $cheque->fresh();
        });
    }

    /**
     * Undo a clearing or a bouncing that was recorded in error, returning the cheque to
     * outstanding — because that is what it is again.
     *
     * The settlement entry is not deleted; an opposing entry cancels it. The cheque can
     * then be settled again (perhaps correctly this time), which is why each settlement
     * carries its own attempt number: reusing the idempotency key of the settlement we
     * just reversed would make the second one silently post nothing at all.
     */
    public function reverseSettlement(Cheque $cheque, string $reason, User $by): Cheque
    {
        if (! $cheque->isSettled() || ! $cheque->settlementEntry) {
            throw new OperationStateException(
                "Only a cleared or bounced cheque can be reversed; this one is [{$cheque->status}]."
            );
        }

        if (! $this->policy->canReverse($by)) {
            throw new OperationStateException('This user may not reverse cheque settlements.');
        }

        return DB::transaction(function () use ($cheque, $reason, $by) {
            $reversal = $this->poster->reverse($cheque->settlementEntry, $reason, $by->id);

            $cheque->forceFill([
                'status' => Cheque::PENDING,
                'bank_account_id' => null,
                'reversal_entry_id' => $reversal->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
            ])->save();

            return $cheque->fresh();
        });
    }

    /* ---------------------------------------------------------------------- */

    private function register(string $direction, Party $party, int $amount, Carbon $dueDate, ?string $serial, array $data, array $lines): Cheque
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('مبلغ چک باید بزرگ‌تر از صفر باشد.');
        }

        return DB::transaction(function () use ($direction, $party, $amount, $dueDate, $serial, $data, $lines) {
            $cheque = Cheque::create([
                'uuid' => (string) Str::uuid(),
                'direction' => $direction,
                'party_id' => $party->id,
                'amount' => $amount,
                'due_date' => $dueDate->toDateString(),
                'serial' => $serial,
                'bank_name' => $data['bank_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => Cheque::PENDING,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => ($direction === Cheque::RECEIVABLE ? 'دریافت چک از ' : 'صدور چک برای ').$party->name,
                'idempotency_key' => "cheque:{$cheque->uuid}",
                'source' => $cheque,
                'created_by' => $data['created_by'] ?? null,
            ], $lines);

            $cheque->update(['journal_entry_id' => $entry->id]);

            return $cheque->fresh();
        });
    }

    private function settle(Cheque $cheque, string $status, string $label, array $lines, ?int $bankAccountId, ?int $by): Cheque
    {
        return DB::transaction(function () use ($cheque, $status, $label, $lines, $bankAccountId, $by) {
            $attempt = (int) $cheque->settlement_attempts;

            $entry = $this->poster->post([
                'entry_date' => Carbon::now(JalaliPeriod::TIMEZONE),
                'description' => "{$label} {$cheque->serial} — {$cheque->party->name}",
                'idempotency_key' => "cheque:{$cheque->uuid}:{$status}:{$attempt}",
                'source' => $cheque,
                'created_by' => $by,
            ], $lines);

            $cheque->forceFill([
                'status' => $status,
                'settlement_entry_id' => $entry->id,
                'bank_account_id' => $bankAccountId,
                'settled_by' => $by,
                'settled_at' => now(),
                'settlement_attempts' => $attempt + 1,
            ])->save();

            return $cheque->fresh();
        });
    }

    private function assertPending(Cheque $cheque): void
    {
        if (! $cheque->isPending()) {
            throw new InvalidArgumentException("Cheque {$cheque->uuid} is already {$cheque->status}.");
        }
    }
}
