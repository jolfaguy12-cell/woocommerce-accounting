<?php

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stats' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
