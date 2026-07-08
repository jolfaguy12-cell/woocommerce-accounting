<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'received_qty' => 'integer',
        'unit_price' => 'integer',
        'shipping_allocated' => 'integer',
        'landed_unit_cost' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function costItem(): BelongsTo
    {
        return $this->belongsTo(CostItem::class);
    }
}
