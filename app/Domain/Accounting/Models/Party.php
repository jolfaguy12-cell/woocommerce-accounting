<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Costing\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    protected $guarded = [];

    protected $casts = [
        'credit_limit' => 'integer',
    ];

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'supplier_party_id');
    }
}
