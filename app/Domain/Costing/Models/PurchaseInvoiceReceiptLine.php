<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class PurchaseInvoiceReceiptLine extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'package_count' => 'integer',
        'via_toggle' => 'boolean',
    ];

    /** Transient — set by the caller right before save() so tapActivity() can attach it; never persisted on the model itself. */
    public ?string $activityReason = null;

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceReceipt::class, 'receipt_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'purchase_invoice_line_id');
    }

    /** Only qty corrections are audited — package/label are cosmetic. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['qty'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($this->activityReason) {
            $activity->properties = $activity->properties->put('reason', $this->activityReason);
        }
    }
}
