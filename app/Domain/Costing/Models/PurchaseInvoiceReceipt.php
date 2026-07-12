<?php

namespace App\Domain\Costing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoiceReceipt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'received_at' => 'date',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceReceiptLine::class, 'receipt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
