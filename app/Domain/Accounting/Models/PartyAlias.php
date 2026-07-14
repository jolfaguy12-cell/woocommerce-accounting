<?php

namespace App\Domain\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * "These two party ids are the same person." That is the entire merge record —
 * no journal line moves, no id is reused, nothing posted is edited.
 *
 * `snapshot` freezes what the absorbed party looked like at the moment of the
 * merge, so the decision stays auditable even after the survivor is edited.
 */
class PartyAlias extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
        'merged_at' => 'datetime',
    ];

    /** The surviving identity. */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /** The identity that was absorbed — still present, still carrying its own journal lines. */
    public function mergedParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'merged_party_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['party_id', 'merged_party_id', 'reason', 'merged_by'])
            ->useLogName('party_merge')
            ->dontSubmitEmptyLogs();
    }
}
