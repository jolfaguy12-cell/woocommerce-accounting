<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Supplier-role data. Contact details are shared identity and stay on Party. */
class SupplierProfile extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'payment_terms_days' => 'integer',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['shop_name', 'payment_terms_days', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
