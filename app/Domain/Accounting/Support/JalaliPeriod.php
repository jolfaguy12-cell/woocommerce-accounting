<?php

namespace App\Domain\Accounting\Support;

use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;

class JalaliPeriod
{
    public const TIMEZONE = 'Asia/Tehran';

    /** Jalali period key for a date, e.g. "1405-04". */
    public static function fromDate(Carbon $date): string
    {
        return Jalalian::fromCarbon($date->copy()->setTimezone(self::TIMEZONE))->format('Y-m');
    }

    /** Gregorian [start, end] dates of the Jalali month containing $date (Tehran time). */
    public static function bounds(Carbon $date): array
    {
        $j = Jalalian::fromCarbon($date->copy()->setTimezone(self::TIMEZONE));
        $first = (new Jalalian($j->getYear(), $j->getMonth(), 1))->toCarbon()->startOfDay();
        $last = (new Jalalian($j->getYear(), $j->getMonth(), $j->getMonthDays()))->toCarbon()->endOfDay();

        return [$first, $last];
    }
}
