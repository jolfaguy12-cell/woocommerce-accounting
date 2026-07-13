<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Models\Concerns\IsFinancialOperation;
use App\Domain\Expenses\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A direct deposit into, or withdrawal from, one of our accounts, against an
 * explicit counter-account.
 *
 * Deliberately NOT a replacement for anything that already exists: a categorised
 * expense stays with ExpenseRecorder, a customer receipt with PaymentRecorder, a
 * gateway settlement with the Zibal importer. This covers the movements that had
 * no home at all — most importantly income, which nothing in the system could
 * record (account 4900 had no writer).
 */
class AccountTransaction extends Model
{
    use IsFinancialOperation, LogsActivity;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /** Frozen once posted — see IsFinancialOperation. */
    public const FINANCIAL_COLUMNS = [
        'bank_account_id', 'direction', 'counter_account_id', 'amount', 'transaction_date', 'party_id',
    ];

    public const PURPOSES = [
        'income' => 'دریافت درآمد',
        'bank_fee' => 'کارمزد / هزینه بانکی',
        'correction' => 'اصلاح و تعدیل',
        'other' => 'سایر',
    ];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'transaction_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function isDeposit(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? $this->purpose;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'direction', 'bank_account_id', 'counter_account_id',
                'journal_entry_id', 'reversal_entry_id', 'approved_by', 'reversed_by', 'cancelled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
