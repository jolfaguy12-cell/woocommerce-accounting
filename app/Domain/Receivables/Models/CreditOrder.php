<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'total_due' => 'integer',
        'paid_total' => 'integer',
        'due_date' => 'date',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function remaining(): int
    {
        return max(0, $this->total_due - $this->paid_total);
    }
}
