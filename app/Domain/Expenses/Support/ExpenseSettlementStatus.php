<?php

namespace App\Domain\Expenses\Support;

/**
 * How much of an expense the company has actually paid.
 *
 * Derived, never stored. The remaining payable is read back out of `journal_lines`
 * every time it is asked for (ExpenseSettlementService), because a stored "paid"
 * flag and the ledger can disagree — and when they do, it is the flag that gets
 * believed and the ledger that is right.
 */
enum ExpenseSettlementStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public static function forRemaining(int $total, int $remaining): self
    {
        if ($remaining <= 0) {
            return self::Paid;
        }

        return $remaining >= $total ? self::Unpaid : self::Partial;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'پرداخت‌نشده',
            self::Partial => 'بخشی پرداخت‌شده',
            self::Paid => 'پرداخت‌شده',
        };
    }

    /**
     * The canonical status <x-ui.status> knows how to render (StatusPresenter). The
     * component draws a dot AND the label — a settlement state is never conveyed by
     * colour alone.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Unpaid => 'pending',
            self::Partial => 'processing',
            self::Paid => 'completed',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
