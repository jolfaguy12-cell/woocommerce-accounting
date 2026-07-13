<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of «برنامه اقساط».
 *
 * The four parts are kept apart — «اصل وام», «سود», «کارمزد», «جریمه دیرکرد» — because
 * they post to four different accounts. A single lump `amount` would have to be split
 * at posting time by guessing, and a guess about how much of a payment was interest is
 * a guess about the company's profit.
 *
 * `status` here is a SCHEDULE status, not a ledger one: `overdue` means the due date
 * has passed. It moves no money — being late does not make us owe more.
 */
class LoanInstallment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const OVERDUE = 'overdue';

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'payment_attempts' => 'integer',
        'amount' => 'integer',
        'principal_part' => 'integer',
        'interest_part' => 'integer',
        'fee_part' => 'integer',
        'penalty_part' => 'integer',
        'paid_amount' => 'integer',
        'paid_at' => 'date',
        'due_date' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    /**
     * Still owing.
     *
     * A payment that is reversed returns the installment to `pending`, because that is
     * simply the truth: the money came back, so the installment is owed again. Parking
     * it in a `reversed` status instead would hide a real obligation behind a word — the
     * schedule would say "handled" while the ledger says "outstanding". The reversal
     * survives in reversal_entry_id, the two journal entries, and the activity log.
     */
    public function isOutstanding(): bool
    {
        return in_array($this->status, [self::PENDING, self::OVERDUE], true);
    }

    public function wasReversed(): bool
    {
        return $this->reversal_entry_id !== null;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /** Late as of today. Derived on read — never a stored truth that can go stale. */
    public function isLate(?Carbon $asOf = null): bool
    {
        return $this->isOutstanding()
            && $this->due_date !== null
            && $this->due_date->lt($asOf ?? Carbon::now('Asia/Tehran')->startOfDay());
    }

    /** What this installment is worth in total: principal + interest + fee + penalty. */
    public function total(): int
    {
        return (int) $this->principal_part + (int) $this->interest_part
            + (int) $this->fee_part + (int) $this->penalty_part;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PAID => 'پرداخت‌شده',
            self::OVERDUE => 'معوق',
            default => 'در انتظار',
        };
    }

    public function badgeStatus(): string
    {
        return match ($this->status) {
            self::PAID => 'completed',
            self::OVERDUE => 'failed',
            default => 'pending',
        };
    }
}
