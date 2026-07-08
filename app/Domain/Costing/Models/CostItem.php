<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function costHistory(): HasMany
    {
        return $this->hasMany(CostHistory::class);
    }

    public function wholesalePrices(): HasMany
    {
        return $this->hasMany(WholesalePrice::class);
    }

    public function latestCost(): ?CostHistory
    {
        return $this->costHistory()->orderByDesc('effective_at')->orderByDesc('id')->first();
    }

    public function latestWholesalePrice(): ?WholesalePrice
    {
        return $this->wholesalePrices()->orderByDesc('effective_at')->orderByDesc('id')->first();
    }
}
