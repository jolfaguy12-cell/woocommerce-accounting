<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A cheque, in either direction.
 *
 * A cheque is a promise, and the ledger records the promise the day it is made rather
 * than the day it is kept: registering one moves the balance out of the receivable or
 * payable and into 1250/2100, where "owed on paper" sits apart from "owed". Only
 * CLEARING it touches a real bank account — which is exactly why `bank_account_id` is
 * null until then.
 *
 * There is no cheque balance engine here, and there must never be one: accounts 1250
 * and 2100 in the ledger ARE the cheque balances.
 */
class Cheque extends Model
{
    use LogsActivity;

    public const PENDING = 'pending';

    public const CLEARED = 'cleared';

    public const BOUNCED = 'bounced';

    public const CANCELLED = 'cancelled';

    public const RECEIVABLE = 'receivable';

    public const PAYABLE = 'payable';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'settlement_attempts' => 'integer',
        'due_date' => 'date',
        'settled_at' => 'datetime',
        'reversed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

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

    public function settlementEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'settlement_entry_id');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReceivable(): bool
    {
        return $this->direction === self::RECEIVABLE;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Settled either way — cleared or bounced. Both are decisions, and both posted. */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::CLEARED, self::BOUNCED], true);
    }

    /** Past its due date and still outstanding. Derived on read, never stored. */
    public function isLate(?Carbon $asOf = null): bool
    {
        return $this->isPending()
            && $this->due_date !== null
            && $this->due_date->lt($asOf ?? Carbon::now('Asia/Tehran')->startOfDay());
    }

    public function directionLabel(): string
    {
        return $this->isReceivable() ? 'چک دریافتی' : 'چک پرداختی';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::CLEARED => 'وصول‌شده',
            self::BOUNCED => 'برگشتی',
            self::CANCELLED => 'ابطال‌شده',
            default => 'در جریان',
        };
    }

    public function badgeStatus(): string
    {
        return match ($this->status) {
            self::CLEARED => 'completed',
            self::BOUNCED => 'failed',
            self::CANCELLED => 'cancelled',
            default => 'pending',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'direction', 'bank_account_id', 'due_date',
                'journal_entry_id', 'settlement_entry_id', 'reversal_entry_id',
                'settled_by', 'reversed_by', 'cancelled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
