<?php

namespace App\Domain\Receivables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Per-order breakdown of a single payment/write-off that may span several CreditOrders (see CreditOrderAllocator). */
class CreditOrderSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function creditOrder(): BelongsTo
    {
        return $this->belongsTo(CreditOrder::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
