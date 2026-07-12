<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCreditAdjustment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
