<?php

namespace App\Domain\Products\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductNote extends Model
{
    protected $guarded = [];

    protected $casts = [
        'multiplier' => 'decimal:3',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductMirror::class, 'product_mirror_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
