<?php

namespace App\Domain\Receivables\Support;

/**
 * A loan's lifecycle.
 *
 *   draft ──submit──> pending_approval ──approve──> active ──> paid
 *     │                      │                        │
 *     │                      │                        ├──> overdue (derived, no ledger effect)
 *     └──── cancel ──────────┘                        └──reverse──> reversed
 *
 * Only `active` and beyond have touched the ledger. `overdue` is a DERIVED state:
 * an installment falling due changes nothing in the accounts — we already owed the
 * money, and being late about it does not make us owe more. It is a flag for
 * humans, and it must never be allowed to move a balance.
 *
 * Distinct from OperationStatus (draft/pending/posted/reversed/cancelled) because a
 * loan is not a single event: it stays alive for years, repaying, and `posted` says
 * nothing useful about a contract with eleven installments left.
 */
enum LoanStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::PendingApproval => 'در انتظار تأیید',
            self::Active => 'جاری',
            self::Paid => 'تسویه‌شده',
            self::Overdue => 'معوق',
            self::Cancelled => 'لغوشده',
            self::Reversed => 'برگشت‌خورده',
        };
    }

    /**
     * The design system pins exactly nine status tokens and no domain gets to invent
     * a tenth. The loan lifecycle maps onto them and supplies its own wording
     * through <x-ui.status>'s `label` prop.
     */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::PendingApproval => 'pending',
            self::Active => 'processing',
            self::Paid => 'completed',
            self::Overdue => 'failed',
            self::Cancelled => 'cancelled',
            self::Reversed => 'archived',
        };
    }

    /** Has the disbursement reached the ledger? */
    public function isDisbursed(): bool
    {
        return in_array($this, [self::Active, self::Paid, self::Overdue, self::Reversed], true);
    }

    /** Can still take installments. */
    public function isRepaying(): bool
    {
        return in_array($this, [self::Active, self::Overdue], true);
    }

    /** Nothing posted yet, so abandoning it costs nothing. */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
