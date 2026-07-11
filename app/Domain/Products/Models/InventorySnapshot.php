<?php

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'total_units' => 'integer',
        'total_value' => 'integer',
        'computed_at' => 'datetime',
    ];
}
