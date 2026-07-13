<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Models\Concerns\IsFinancialOperation;
use App\Domain\Accounting\Support\PartyOffsetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Netting two balances the same party holds against each other. No cash moves. */
class PartyOffset extends Model
{
    use IsFinancialOperation, LogsActivity;

    /** Frozen once posted — see IsFinancialOperation. */
    public const FINANCIAL_COLUMNS = ['party_id', 'type', 'amount', 'offset_date'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'type' => PartyOffsetType::class,
        'offset_date' => 'date',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'party_id', 'type', 'amount', 'journal_entry_id', 'reversal_entry_id',
                'approved_by', 'reversed_by', 'cancelled_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
