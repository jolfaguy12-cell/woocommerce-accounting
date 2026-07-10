<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;

/** Package weight >= min_weight_grams triggers this tier's cost; highest matching tier wins. */
class PackagingCostTier extends Model
{
    protected $guarded = [];

    protected $casts = [
        'min_weight_grams' => 'integer',
        'cost' => 'integer',
    ];
}
