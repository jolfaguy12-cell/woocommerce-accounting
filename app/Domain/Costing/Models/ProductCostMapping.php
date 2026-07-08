<?php

namespace App\Domain\Costing\Models;

use App\Domain\Products\Models\ProductMirror;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCostMapping extends Model
{
    protected $guarded = [];

    protected $casts = [
        'multiplier' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductMirror::class, 'product_mirror_id');
    }

    public function costItem(): BelongsTo
    {
        return $this->belongsTo(CostItem::class);
    }

    public function costGroup(): BelongsTo
    {
        return $this->belongsTo(CostGroup::class);
    }
}
