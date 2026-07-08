<?php

namespace App\Domain\Costing\Models;

use Illuminate\Database\Eloquent\Model;

class CostHistory extends Model
{
    protected $table = 'cost_history';

    protected $guarded = [];

    protected $casts = [
        'unit_cost' => 'integer',
        'landed_unit_cost' => 'integer',
        'effective_at' => 'date',
    ];
}
