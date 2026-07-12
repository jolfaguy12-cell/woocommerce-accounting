<?php

namespace App\Domain\Costing\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Party;
use App\Domain\Expenses\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseInvoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'shipping_cost' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_party_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceReceipt::class, 'purchase_invoice_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_invoice_id');
    }
}
