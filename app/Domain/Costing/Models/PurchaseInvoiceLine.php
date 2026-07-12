<?php

namespace App\Domain\Costing\Models;

use App\Domain\Products\Models\ProductMirror;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoiceLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'received_qty' => 'integer',
        'returned_qty' => 'integer',
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductMirror::class, 'product_mirror_id');
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceReceiptLine::class, 'purchase_invoice_line_id');
    }

    public function returnLines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'purchase_invoice_line_id');
    }

    /** How much of what's been received on this line has not yet been sent back. */
    public function returnableQty(): int
    {
        return $this->received_qty - $this->returned_qty;
    }
}
