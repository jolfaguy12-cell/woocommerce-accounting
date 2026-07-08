<?php

namespace App\Domain\Sync\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalIdMap extends Model
{
    protected $table = 'external_id_map';

    protected $guarded = [];

    public static function remember(string $externalType, string|int $externalId, Model $internal, string $system = 'hub'): self
    {
        return static::firstOrCreate([
            'external_system' => $system,
            'external_type' => $externalType,
            'external_id' => (string) $externalId,
        ], [
            'internal_type' => $internal->getMorphClass(),
            'internal_id' => $internal->getKey(),
        ]);
    }
}
