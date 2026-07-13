<?php

namespace App\Domain\Receivables\Support;

/**
 * How a loan's total interest is arrived at.
 *
 * Deliberately only three, and deliberately simple: this system SCHEDULES interest,
 * it does not price it. The bank (or the partner) has already decided what the loan
 * costs; what we need is to book that cost against the right installments. So there
 * is no amortisation engine and no reducing-balance formula — either the total is
 * given to us outright, or it is a flat annual rate on the original principal, which
 * is how Iranian bank facilities are quoted in practice.
 *
 * If a real amortising loan ever arrives, it gets a fourth case here — never a
 * hand-adjusted schedule, because a schedule edited by hand is a schedule nobody can
 * reproduce.
 */
enum InterestMethod: string
{
    /** No interest at all — the common case for a partner or family loan. */
    case None = 'none';

    /** A flat annual percentage of the ORIGINAL principal, not the reducing balance. */
    case Flat = 'flat';

    /** The total interest is simply stated up front, in Toman. */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::None => 'بدون سود',
            self::Flat => 'نرخ سالانه ثابت (درصد)',
            self::Fixed => 'مبلغ کل سود (ثابت)',
        };
    }

    public function needsRate(): bool
    {
        return $this === self::Flat;
    }

    public function needsAmount(): bool
    {
        return $this === self::Fixed;
    }

    /**
     * Total interest over the life of the loan, in whole Toman.
     *
     * $rate is an annual percentage; $months is the term. Rounded once, here, so the
     * schedule and the loan header can never disagree about the total.
     */
    public function totalInterest(int $principal, ?float $rate, ?int $fixedAmount, int $months): int
    {
        return match ($this) {
            self::None => 0,
            self::Fixed => max(0, (int) $fixedAmount),
            self::Flat => $rate === null || $rate <= 0 || $months <= 0
                ? 0
                : (int) round($principal * ($rate / 100) * ($months / 12)),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
