<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Models\Concerns\IsFinancialOperation;
use App\Domain\Accounting\Support\PartnerOperationType;
use App\Domain\Expenses\Models\BankAccount;
use App\Domain\Receivables\Models\Loan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Capital, drawings, profit shares and partner loans — each on its own accounts. */
class PartnerOperation extends Model
{
    use IsFinancialOperation, LogsActivity;

    /** Frozen once posted — see IsFinancialOperation. */
    public const FINANCIAL_COLUMNS = [
        'party_id', 'type', 'amount', 'operation_date', 'bank_account_id', 'counter_account_id',
    ];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'type' => PartnerOperationType::class,
        'loan_terms' => 'array',
        'operation_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
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

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'party_id', 'type', 'amount', 'bank_account_id', 'journal_entry_id',
                'reversal_entry_id', 'approved_by', 'reversed_by', 'cancelled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
