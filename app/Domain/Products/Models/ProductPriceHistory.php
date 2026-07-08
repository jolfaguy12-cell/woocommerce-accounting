<?php

namespace App\Domain\Products\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPriceHistory extends Model
{
    protected $table = 'product_price_history';

    protected $guarded = [];

    protected $casts = [
        'old_price' => 'integer',
        'new_price' => 'integer',
        'changed_at' => 'datetime',
    ];
}
