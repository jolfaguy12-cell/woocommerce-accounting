<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CostGroup extends Model
{
    protected $guarded = [];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(CostItem::class, 'cost_group_items');
    }
}
