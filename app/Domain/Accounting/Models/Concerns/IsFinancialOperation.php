<?php

namespace App\Domain\Accounting\Models\Concerns;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Support\OperationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Everything an approvable, reversible financial operation shares: its status
 * predicates, its audit trail, and the guarantee that its financial substance
 * cannot change once it has hit the ledger.
 *
 * The implementing model declares FINANCIAL_COLUMNS — the fields that decide
 * what was actually posted. After posting they are frozen: a wrong amount is
 * corrected by reversing and re-creating, never by an edit, because an edit
 * would leave the journal entry saying one thing and the operation another.
 * (Same contract JournalEntry/JournalLine enforce with ImmutableJournalException.)
 */
trait IsFinancialOperation
{
    protected static function bootIsFinancialOperation(): void
    {
        static::updating(function (self $operation) {
            $wasPosted = OperationStatus::from($operation->getOriginal('status'))->isPosted();
            $touched = array_intersect(array_keys($operation->getDirty()), static::FINANCIAL_COLUMNS);

            if ($wasPosted && $touched !== []) {
                $columns = implode(', ', $touched);

                throw new OperationStateException(
                    "This operation is already posted to the ledger; [{$columns}] can no longer change. "
                    .'Reverse it and record a new one — a posted entry is corrected by an opposing entry, never by an edit.'
                );
            }
        });
    }

    /**
     * Deliberately NOT named status(): Eloquent resolves an unknown property
     * through a same-named method as a relation, so a `status()` method sitting
     * next to a `status` column is a trap waiting for the first model instance
     * whose attribute isn't loaded.
     */
    public function operationStatus(): OperationStatus
    {
        return OperationStatus::from($this->status);
    }

    public function isPosted(): bool
    {
        return $this->operationStatus() === OperationStatus::Posted;
    }

    public function isReversed(): bool
    {
        return $this->operationStatus() === OperationStatus::Reversed;
    }

    public function isPendingApproval(): bool
    {
        return $this->operationStatus() === OperationStatus::PendingApproval;
    }

    public function isCancelled(): bool
    {
        return $this->operationStatus() === OperationStatus::Cancelled;
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
