<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderLabel extends Model
{
    protected $guarded = [];

    /** Names are mostly Persian free text, so identity is the name itself — slug is only set for a few well-known predefined labels (e.g. 'wholesale'). */
    public static function findOrCreateByName(string $name): self
    {
        return static::firstOrCreate(['name' => trim($name)], ['color' => 'light']);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'order_label_order');
    }
}
