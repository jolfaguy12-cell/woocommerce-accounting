<?php

namespace App\Domain\Orders\Models;

use App\Domain\Products\Models\ProductMirror;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'integer',
        'line_subtotal' => 'integer',
        'line_total' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productMirror(): BelongsTo
    {
        return $this->belongsTo(ProductMirror::class);
    }
}
