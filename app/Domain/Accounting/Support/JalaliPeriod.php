<?php

namespace App\Domain\Accounting\Support;

use Illuminate\Support\Carbon;
use Morilog\Jalali\Jalalian;

class JalaliPeriod
{
    public const TIMEZONE = 'Asia/Tehran';

    /**
     * Parse a hub-supplied GMT/UTC timestamp string into the app's storage
     * timezone (Asia/Tehran). Required, not cosmetic: APP_TIMEZONE=Asia/Tehran
     * makes Eloquent re-label naive MySQL datetime strings as Tehran wall-clock
     * on every read (MySQL datetime columns carry no timezone). A Carbon
     * instance built with an explicit 'UTC' source and stored as-is round-trips
     * mislabeled — silently shifted by Tehran's +03:30 offset. Converting to
     * Tehran here, before the value is ever assigned to a model attribute,
     * keeps write and read symmetric. Never store a hub GMT value without
     * routing it through this first.
     */
    public static function parseHubGmt(?string $raw): ?Carbon
    {
        return $raw ? Carbon::parse($raw, 'UTC')->setTimezone(self::TIMEZONE) : null;
    }

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

    /**
     * Short Jalali date+time, e.g. "1405/04/14 22:21" (Tehran time).
     *
     * For REAL timestamps only — when something actually happened, to the minute:
     * a created_at, an approval, a role activation. If the value came out of a
     * `date` column it has no time, and printing "00:00" next to it is not a
     * precise answer, it is a made-up one. Use fmtDate() there.
     */
    public static function fmtDateTime(Carbon $date): string
    {
        return Jalalian::fromCarbon($date->copy()->setTimezone(self::TIMEZONE))->format('Y/m/d H:i');
    }

    /** Jalali date only, e.g. "1405/04/14" — for date columns (due dates, invoice dates). */
    public static function fmtDate(Carbon $date): string
    {
        return Jalalian::fromCarbon($date->copy()->setTimezone(self::TIMEZONE))->format('Y/m/d');
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

    /** The period key immediately before $period, e.g. "1405-01" for "1405-02". */
    public static function previous(string $period): string
    {
        [$year, $month] = array_map('intval', explode('-', $period));
        $month--;
        if ($month < 1) {
            $month = 12;
            $year--;
        }

        return sprintf('%04d-%02d', $year, $month);
    }

    /** All 12 period keys of the Jalali year that $period falls in, in order (Farvardin..Esfand). */
    public static function monthsOfYear(string $period): array
    {
        [$year] = array_map('intval', explode('-', $period));

        return array_map(fn ($m) => sprintf('%04d-%02d', $year, $m), range(1, 12));
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
