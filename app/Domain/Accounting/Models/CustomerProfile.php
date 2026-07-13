<?php

namespace App\Domain\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Customer-role data. Name, phone, email and address are shared identity and stay on Party. */
class CustomerProfile extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'credit_limit' => 'integer',
        'is_wholesale' => 'boolean',
        'wholesale_labeled_at' => 'datetime',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function wholesaleLabeledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wholesale_labeled_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['credit_limit', 'is_wholesale', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
