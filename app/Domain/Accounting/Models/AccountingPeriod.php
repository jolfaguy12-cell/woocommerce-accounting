<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Support\JalaliPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AccountingPeriod extends Model
{
    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'locked_at' => 'datetime',
    ];

    /** Find or create the period row for the Jalali month containing $date. */
    public static function forDate(Carbon $date): self
    {
        $key = JalaliPeriod::fromDate($date);

        return static::firstOrCreate(['jalali_period' => $key], array_combine(
            ['starts_at', 'ends_at'],
            JalaliPeriod::bounds($date),
        ));
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    public function isSoftClosed(): bool
    {
        return $this->status === 'soft_closed';
    }
}
