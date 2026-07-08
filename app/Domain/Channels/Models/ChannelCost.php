<?php

namespace App\Domain\Channels\Models;

use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelCost extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
        'occurred_at' => 'date',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
