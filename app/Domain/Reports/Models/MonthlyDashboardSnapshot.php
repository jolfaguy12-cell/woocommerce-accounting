<?php

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyDashboardSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'new_customers' => 'integer',
        'orders_count' => 'integer',
        'gross_sales' => 'integer',
        'stock_count' => 'integer',
        'computed_at' => 'datetime',
    ];
}
