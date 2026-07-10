<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Current balance (debit − credit), summed across every line for this account.
     * Reversal entries post their own opposing lines rather than mutating the
     * original, so an unfiltered sum already nets out reversed activity correctly.
     */
    public function balance(): int
    {
        return (int) $this->lines()->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')->value('balance');
    }
}
