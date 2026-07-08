<?php

namespace App\Domain\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'valid_statuses' => 'array',
        'is_active' => 'boolean',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(ChannelSource::class);
    }
}
