<?php

namespace App\Support\Design;

/**
 * Single source of truth mapping a status to its design-system token.
 *
 * Every surface that renders a status (badge, table cell, KPI card, chart
 * legend) resolves it here, so the same status can never render two different
 * ways. The `token` maps onto the semantic CSS variables declared in
 * resources/css/tailadmin.css (--color-status-*), which are dark-mode aware.
 *
 * Accessibility: callers must render the icon/dot AND the label — never rely
 * on colour alone to convey the status (WCAG "color-not-only").
 *
 * Mirrors the existing App\Domain\Orders\Support\OrderStatusPresenter pattern.
 */
class StatusPresenter
{
    /** Canonical statuses. Keys are stable; adding one is additive. */
    private const STATUSES = [
        'draft' => ['label' => 'پیش‌نویس', 'token' => 'draft'],
        'pending' => ['label' => 'در انتظار', 'token' => 'pending'],
        'processing' => ['label' => 'در حال پردازش', 'token' => 'processing'],
        'approved' => ['label' => 'تأییدشده', 'token' => 'approved'],
        'completed' => ['label' => 'تکمیل‌شده', 'token' => 'completed'],
        'cancelled' => ['label' => 'لغوشده', 'token' => 'cancelled'],
        'failed' => ['label' => 'ناموفق', 'token' => 'failed'],
        'needs_review' => ['label' => 'نیازمند بازبینی', 'token' => 'needs-review'],
        'archived' => ['label' => 'بایگانی‌شده', 'token' => 'archived'],
    ];

    /**
     * Resolve a status to its label + token. Unknown values never break a
     * page: they fall back to the neutral draft token and show their raw value
     * (same defensive contract as OrderStatusPresenter).
     *
     * @return array{label: string, token: string, key: string}
     */
    public static function resolve(string $status): array
    {
        $key = str_replace('-', '_', strtolower(trim($status)));

        if (! isset(self::STATUSES[$key])) {
            return ['label' => $status, 'token' => 'draft', 'key' => $key];
        }

        return self::STATUSES[$key] + ['key' => $key];
    }

    /** @return array<string, array{label: string, token: string}> */
    public static function all(): array
    {
        return self::STATUSES;
    }

    /** Token for a financial delta: profit/loss colouring driven by sign. */
    public static function trend(int|float $delta): string
    {
        return match (true) {
            $delta > 0 => 'up',
            $delta < 0 => 'down',
            default => 'flat',
        };
    }
}
