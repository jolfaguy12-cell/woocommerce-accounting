<?php

namespace App\Domain\Accounting\Support;

/**
 * The lifecycle of a new financial operation (D4: new operations only — the
 * live supplier/customer/expense/purchase flows keep posting immediately).
 *
 *   draft ──submit──> pending_approval ──approve──> posted ──reverse──> reversed
 *     │                      │
 *     └──── cancel ──────────┴──> cancelled
 *
 * The only status that owns a journal entry is `posted` (and `reversed`, which
 * keeps the original entry and adds an opposing one). Nothing before `posted`
 * has ever touched the ledger, which is why cancelling is free and reversing is
 * not: a posted operation is corrected by a reversal entry, never by an edit.
 */
enum OperationStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::PendingApproval => 'در انتظار تأیید',
            self::Posted => 'ثبت‌شده',
            self::Reversed => 'برگشت‌خورده',
            self::Cancelled => 'لغوشده',
        };
    }

    /**
     * The canonical design-system status this renders as.
     *
     * The design system keeps exactly nine status tokens, each owning one colour,
     * and no two statuses may share one — so a domain lifecycle does not get to
     * invent colours. It maps onto the nine and supplies its own wording through
     * <x-ui.status>'s `label` override, which exists for precisely this.
     */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::PendingApproval => 'pending',
            self::Posted => 'completed',
            self::Reversed => 'archived',
            self::Cancelled => 'cancelled',
        };
    }

    /** Has this operation put lines in the ledger? */
    public function isPosted(): bool
    {
        return $this === self::Posted || $this === self::Reversed;
    }

    /** Can still be abandoned without any accounting consequence. */
    public function isCancellable(): bool
    {
        return $this === self::Draft || $this === self::PendingApproval;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
