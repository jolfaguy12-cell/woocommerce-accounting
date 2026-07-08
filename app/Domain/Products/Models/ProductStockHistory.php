<?php

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStockHistory extends Model
{
    protected $table = 'product_stock_history';

    protected $guarded = [];

    protected $casts = [
        'old_quantity' => 'integer',
        'new_quantity' => 'integer',
        'changed_at' => 'datetime',
    ];
}
