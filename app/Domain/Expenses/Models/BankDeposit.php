<?php

namespace App\Domain\Expenses\Models;

use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankDeposit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw_row' => 'array',
        'registered_at' => 'datetime',
        'deposited_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankDepositImport::class, 'import_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isPosted(): bool
    {
        return $this->journal_entry_id !== null;
    }
}
