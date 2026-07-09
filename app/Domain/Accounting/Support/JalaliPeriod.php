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

    /** Short Jalali date+time for display, e.g. "1405/04/14 22:21" (Tehran time). */
    public static function fmtDateTime(Carbon $date): string
    {
        return Jalalian::fromCarbon($date->copy()->setTimezone(self::TIMEZONE))->format('Y/m/d H:i');
    }

    /** Persian relative time, e.g. "۳ ساعت پیش" / "۲ روز پیش", for recency-style display columns. */
    public static function humanDiff(Carbon $date): string
    {
        $now = Carbon::now(self::TIMEZONE);
        $date = $date->copy()->setTimezone(self::TIMEZONE);
        $minutes = (int) $date->diffInMinutes($now);

        if ($minutes < 1) {
            return 'همین الان';
        }
        if ($minutes < 60) {
            return "{$minutes} دقیقه پیش";
        }
        if (($hours = (int) $date->diffInHours($now)) < 24) {
            return "{$hours} ساعت پیش";
        }
        if (($days = (int) $date->diffInDays($now)) < 30) {
            return "{$days} روز پیش";
        }
        if (($months = (int) $date->diffInMonths($now)) < 12) {
            return "{$months} ماه پیش";
        }

        return ((int) $date->diffInYears($now)).' سال پیش';
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
