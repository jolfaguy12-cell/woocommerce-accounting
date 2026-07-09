<?php

namespace App\Domain\Products\Models;

use App\Domain\Costing\Models\ProductCostMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductMirror extends Model
{
    protected $table = 'product_mirror';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'price' => 'integer',
        'regular_price' => 'integer',
        'sale_price' => 'integer',
        'stock_quantity' => 'integer',
        'hub_modified_at' => 'datetime',
    ];

    public function variations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_hub_id', 'hub_product_id');
    }

    public function costMapping(): HasOne
    {
        return $this->hasOne(ProductCostMapping::class, 'product_mirror_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    public function stockHistory(): HasMany
    {
        return $this->hasMany(ProductStockHistory::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ProductNote::class);
    }
}
