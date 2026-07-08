<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class OrderShippingCost extends Model
{
    protected $guarded = [];

    protected $casts = [
        'real_cost' => 'integer',
    ];
}
