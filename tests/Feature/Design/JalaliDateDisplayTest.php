<?php

use App\Domain\Accounting\Support\JalaliPeriod;
use Illuminate\Support\Carbon;

/**
 * A date column has no time. Printing "00:00" beside it is not a precise answer,
 * it is an invented one — and it sat next to every due date, invoice date and
 * accounting date in the system, implying a midnight that nobody recorded.
 *
 * The rule: `date` column → fmtDate(). Real timestamp → fmtDateTime(), to the
 * minute, because when something actually happened is worth knowing exactly.
 */
it('prints a date-only value with no time at all', function () {
    $date = Carbon::parse('2026-07-14', 'Asia/Tehran');

    expect(JalaliPeriod::fmtDate($date))->toBe('1405/04/23')
        ->and(JalaliPeriod::fmtDate($date))->not->toContain('00:00');
});

it('keeps the precise time on a real timestamp', function () {
    $moment = Carbon::parse('2026-07-14 22:21:07', 'Asia/Tehran');

    expect(JalaliPeriod::fmtDateTime($moment))->toBe('1405/04/23 22:21');
});

it('renders both in Tehran time whatever the source timezone', function () {
    // 20:30 UTC on the 14th is 00:00 Tehran on the 15th — the date itself moves.
    $utc = Carbon::parse('2026-07-14 20:30:00', 'UTC');

    expect(JalaliPeriod::fmtDate($utc))->toBe('1405/04/24')
        ->and(JalaliPeriod::fmtDateTime($utc))->toBe('1405/04/24 00:00');
});
