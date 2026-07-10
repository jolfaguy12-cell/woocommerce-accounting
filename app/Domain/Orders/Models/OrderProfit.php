<?php

namespace App\Domain\Orders\Models;

use App\Domain\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderProfit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
        'gross_sale' => 'integer',
        'discounts' => 'integer',
        'net_sale' => 'integer',
        'product_cost' => 'integer',
        'cost_breakdown' => 'array',
        'shipping_charged' => 'integer',
        'shipping_real' => 'integer',
        'channel_fee' => 'integer',
        'channel_discount' => 'integer',
        'gateway_fee' => 'integer',
        'package_weight_grams' => 'integer',
        'packaging_cost' => 'integer',
        'gross_profit' => 'integer',
        'operational_profit' => 'integer',
        'calculated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
