<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderGatewayCheck extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'mismatch' => 'boolean',
        'raw_response' => 'array',
        'checked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
