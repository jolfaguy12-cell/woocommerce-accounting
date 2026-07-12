<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceReceiptLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'package_count' => 'integer',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceReceipt::class, 'receipt_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceLine::class, 'purchase_invoice_line_id');
    }
}
