<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One accrual of one Jalali period's salaries. Once posted it is immutable: the
 * only correction is a reversal, which posts an opposing entry and leaves the
 * original exactly where it is.
 *
 * `status` is a plain string (draft | posted | reversed) — see the migration for
 * why it stopped being an ENUM.
 */
class PayrollRun extends Model
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    protected $guarded = [];

    protected $casts = [
        'run_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * «سوابق پرداخت حقوق» tied to THIS run — both the «پرداخت هم‌زمان» posted
     * atomically with the accrual, and any later standalone payment the operator
     * chose to link back to it. Not the whole story of what an employee was
     * paid (paidSalary() sums 2300 across every run), just this run's own trail.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(PartyPayment::class, 'applied');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::REVERSED;
    }

    /** The gross this run accrued — the debit side of its entry. */
    public function grossTotal(): int
    {
        return (int) $this->items->sum('gross');
    }

    public function netTotal(): int
    {
        return (int) $this->items->sum('net');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::POSTED => 'ثبت‌شده',
            self::REVERSED => 'برگشت‌خورده',
            default => 'پیش‌نویس',
        };
    }

    /** A canonical status key <x-ui.status> can render (see StatusPresenter). */
    public function statusBadge(): string
    {
        return match ($this->status) {
            self::POSTED => 'completed',
            self::REVERSED => 'cancelled',
            default => 'draft',
        };
    }
}
