<?php

namespace App\Domain\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'value' => 'json',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::find($key)?->value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** For secrets (e.g. the Telegram bot token) that must never sit in plaintext in the DB. */
    public static function getEncrypted(string $key): ?string
    {
        $value = static::get($key);

        return $value ? Crypt::decryptString($value) : null;
    }

    public static function setEncrypted(string $key, ?string $value): void
    {
        static::set($key, $value ? Crypt::encryptString($value) : null);
    }
}
