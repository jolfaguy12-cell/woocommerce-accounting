<?php

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReviewItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function open(string $type, ?Model $subject = null, array $payload = []): self
    {
        return static::create([
            'type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $payload,
            'status' => 'open',
        ]);
    }
}
