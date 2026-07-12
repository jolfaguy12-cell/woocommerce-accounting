<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'unit_cost' => 'integer',
    ];

    public function return(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'return_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'purchase_invoice_line_id');
    }
}
