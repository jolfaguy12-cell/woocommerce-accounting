<?php

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;

class RawOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'hub_modified_at' => 'datetime',
        'received_at' => 'datetime',
    ];
}
