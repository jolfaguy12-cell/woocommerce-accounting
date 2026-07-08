<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Exceptions\ImmutableJournalException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new ImmutableJournalException('Journal entries are immutable; reverse them instead of deleting.');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }
}
