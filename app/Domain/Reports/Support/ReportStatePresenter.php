<?php

namespace App\Domain\Reports\Support;

/**
 * Persian label + badge color for partner report states.
 * Unknown values still render safely with their raw value (mirrors
 * App\Domain\Orders\Support\OrderStatusPresenter's fallback pattern).
 */
class ReportStatePresenter
{
    private const STATE_LABELS = [
        'draft' => 'پیش‌نویس',
        'needs_review' => 'نیازمند بازبینی',
        'final' => 'نهایی',
        'adjusted' => 'تعدیل‌شده',
    ];

    private const STATE_COLORS = [
        'draft' => 'light',
        'needs_review' => 'warning',
        'final' => 'success',
        'adjusted' => 'info',
    ];

    public static function state(string $state): array
    {
        return [
            'label' => self::STATE_LABELS[$state] ?? $state,
            'color' => self::STATE_COLORS[$state] ?? 'light',
        ];
    }
}
