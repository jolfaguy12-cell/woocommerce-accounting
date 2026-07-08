<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'debit' => 'integer',
        'credit' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new ImmutableJournalException('Journal lines are immutable; reverse the entry instead.');
        });

        static::updating(function () {
            throw new ImmutableJournalException('Journal lines are immutable; reverse the entry instead.');
        });
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
