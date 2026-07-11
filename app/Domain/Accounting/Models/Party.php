<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Costing\Models\PurchaseInvoice;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    protected $guarded = [];

    protected $casts = [
        'credit_limit' => 'integer',
        'is_wholesale' => 'boolean',
        'wholesale_labeled_at' => 'datetime',
    ];

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'supplier_party_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_party_id');
    }
}
