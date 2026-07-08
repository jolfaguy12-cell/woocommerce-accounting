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

    /** Gregorian [start, end] dates for a period key like "1405-03". */
    public static function boundsFor(string $period): array
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $first = new Jalalian($year, $month, 1);
        $last = new Jalalian($year, $month, $first->getMonthDays());

        return [
            Carbon::instance($first->toCarbon())->startOfDay(),
            Carbon::instance($last->toCarbon())->endOfDay(),
        ];
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
