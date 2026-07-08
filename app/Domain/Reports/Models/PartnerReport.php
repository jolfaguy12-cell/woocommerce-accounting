<?php

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerReport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'draft_data' => 'array',
        'snapshot' => 'array',
        'readiness' => 'array',
        'finalized_at' => 'datetime',
    ];

    public function adjustments(): HasMany
    {
        return $this->hasMany(ReportAdjustment::class);
    }

    /** Working aggregates; use snapshot for anything already finalized. */
    public function draftData(): array
    {
        return $this->draft_data ?? [];
    }
}
