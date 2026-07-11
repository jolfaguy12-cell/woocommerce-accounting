<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CreditOrderSettlement::class);
    }

    public function remaining(): int
    {
        return max(0, $this->total_due - $this->paid_total);
    }
}
