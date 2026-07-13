<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Models\Concerns\IsFinancialOperation;
use App\Domain\Expenses\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Money moved between two of our own accounts. It is not income, not an expense
 * and not a payment to anyone — the group's net worth is identical before and
 * after, so the entry only ever touches the two asset accounts (plus an explicit
 * bank fee, which IS a real expense and is posted as one).
 */
class AccountTransfer extends Model
{
    use IsFinancialOperation, LogsActivity;

    /** Frozen once posted — see IsFinancialOperation. */
    public const FINANCIAL_COLUMNS = [
        'from_bank_account_id', 'to_bank_account_id', 'amount', 'bank_fee', 'transfer_date',
    ];

    public const METHODS = [
        'internal' => 'انتقال داخلی',
        'card' => 'کارت به کارت',
        'sheba' => 'پایا / ساتنا (شبا)',
        'cash' => 'نقدی',
        'other' => 'سایر',
    ];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'bank_fee' => 'integer',
        'transfer_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function fromBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_bank_account_id');
    }

    public function toBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_bank_account_id');
    }

    /** What leaves the source account: the transfer plus any fee it carries. */
    public function totalOutflow(): int
    {
        return $this->amount + $this->bank_fee;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'bank_fee', 'from_bank_account_id', 'to_bank_account_id',
                'journal_entry_id', 'reversal_entry_id', 'approved_by', 'reversed_by', 'cancelled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
