<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;

class WholesalePrice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price' => 'integer',
        'effective_at' => 'date',
    ];
}
