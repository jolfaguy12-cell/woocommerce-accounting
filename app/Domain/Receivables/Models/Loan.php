<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Exceptions\OperationStateException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Models\Attachment;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Support\InterestMethod;
use App\Domain\Receivables\Support\LoanDirection;
use App\Domain\Receivables\Support\LoanStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A loan contract — in either direction.
 *
 * Notice what is NOT stored here: the outstanding principal. It is derived from the
 * journal lines this loan is the source of (LoanService::remainingPrincipal), because
 * a stored "remaining" column is a second copy of a number the ledger already knows,
 * and the two only agree until the first crash between the two writes. The installments
 * record what was AGREED; the ledger records what HAPPENED; where they can disagree,
 * the ledger wins.
 *
 * `principal`, `direction` and the interest terms describe the CONTRACT and are frozen
 * once disbursed — changing the terms of a loan that is already in the books is not an
 * edit, it is a different agreement.
 */
class Loan extends Model
{
    use LogsActivity;

    /** Frozen once the disbursement has been posted. */
    public const FINANCIAL_COLUMNS = [
        'party_id', 'direction', 'principal', 'bank_account_id', 'received_at',
        'interest_method', 'interest_rate', 'interest_amount',
    ];

    protected $guarded = [];

    protected $casts = [
        'principal' => 'integer',
        'interest_amount' => 'integer',
        'interest_rate' => 'float',
        'installment_count' => 'integer',
        'received_at' => 'date',
        'maturity_date' => 'date',
        'direction' => LoanDirection::class,
        'interest_method' => InterestMethod::class,
        'status' => LoanStatus::class,
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $loan) {
            // getRawOriginal, not getOriginal: `status` is cast to LoanStatus, and
            // getOriginal would hand back the enum it was already cast to.
            $wasDisbursed = LoanStatus::from($loan->getRawOriginal('status'))->isDisbursed();
            $touched = array_intersect(array_keys($loan->getDirty()), self::FINANCIAL_COLUMNS);

            if ($wasDisbursed && $touched !== []) {
                $columns = implode(', ', $touched);

                throw new OperationStateException(
                    "This loan is already in the ledger; [{$columns}] can no longer change. "
                    .'Reverse it and record a new contract — a posted loan is corrected by an opposing entry, never by an edit.'
                );
            }
        });
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
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

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isReceivable(): bool
    {
        return $this->direction === LoanDirection::Receivable;
    }

    /** «سررسید بعدی» — the next installment still owing. Null once everything is settled. */
    public function nextInstallment(): ?LoanInstallment
    {
        return $this->installments->first(fn (LoanInstallment $i) => $i->isOutstanding());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'principal', 'direction', 'bank_account_id', 'interest_method',
                'interest_rate', 'interest_amount', 'journal_entry_id', 'reversal_entry_id',
                'approved_by', 'reversed_by', 'cancelled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
