<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Partner-role data. Ownership is basis points (1% = 100) so shares stay exact. */
class PartnerProfile extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'ownership_bp' => 'integer',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function ownershipPercent(): float
    {
        return $this->ownership_bp / 100;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['ownership_bp', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
