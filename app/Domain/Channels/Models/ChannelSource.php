<?php

namespace App\Domain\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelSource extends Model
{
    protected $guarded = [];

    protected $casts = [
        'raw_signature' => 'array',
        'order_count' => 'integer',
        'first_seen_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
