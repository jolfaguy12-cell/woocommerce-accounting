<?php

namespace App\Domain\Orders\Support;

/**
 * Persian label + badge color for every status shown on order pages.
 * Unknown values (a future/unmapped status from any source) still render
 * safely with their raw value instead of breaking the page (README §11).
 */
class OrderStatusPresenter
{
    private const ORDER_STATUS_LABELS = [
        'completed' => 'تکمیل‌شده',
        'processing' => 'در حال پردازش',
        'pending' => 'در انتظار پرداخت',
        'cancelled' => 'لغوشده',
        'refunded' => 'مستردشده',
        'trash' => 'حذف‌شده',
        'auto-draft' => 'پیش‌نویس',
        'bslm-completed' => 'باسلام: تکمیل‌شده',
        'bslm-preparation' => 'باسلام: در حال آماده‌سازی',
        'bslm-shipping' => 'باسلام: ارسال‌شده',
        'bslm-wait-vendor' => 'باسلام: در انتظار فروشنده',
        'bslm-rejected' => 'باسلام: ردشده',
    ];

    private const ORDER_STATUS_COLORS = [
        'completed' => 'success',
        'bslm-completed' => 'success',
        'cancelled' => 'error',
        'bslm-rejected' => 'error',
        'trash' => 'error',
        'refunded' => 'warning',
    ];

    private const FINANCIAL_STATE_LABELS = [
        'pending' => 'در انتظار',
        'valid' => 'معتبر',
        'refunded' => 'مستردشده',
        'partially_refunded' => 'استرداد جزئی',
        'cancelled' => 'لغوشده',
        'void' => 'باطل',
    ];

    private const FINANCIAL_STATE_COLORS = [
        'valid' => 'success',
        'cancelled' => 'error',
        'void' => 'error',
    ];

    private const PROFIT_STATUS_LABELS = [
        'ok' => 'سود ثبت‌شده',
        'blocked_missing_cost' => 'مسدود — بدون بها',
        'unknown_source' => 'منبع ناشناخته',
        'needs_review' => 'نیازمند بازبینی',
        'pending' => 'در انتظار',
    ];

    private const PROFIT_STATUS_COLORS = [
        'ok' => 'success',
        'blocked_missing_cost' => 'error',
        'unknown_source' => 'error',
        'needs_review' => 'warning',
    ];

    private const PAYMENT_STATUS_LABELS = [
        'paid' => 'پرداخت‌شده',
        'unpaid' => 'پرداخت‌نشده',
    ];

    public static function orderStatus(string $status): array
    {
        return [
            'label' => self::ORDER_STATUS_LABELS[$status] ?? $status,
            'color' => self::ORDER_STATUS_COLORS[$status] ?? 'light',
        ];
    }

    public static function financialState(string $state): array
    {
        return [
            'label' => self::FINANCIAL_STATE_LABELS[$state] ?? $state,
            'color' => self::FINANCIAL_STATE_COLORS[$state] ?? 'light',
        ];
    }

    public static function profitStatus(string $status): array
    {
        return [
            'label' => self::PROFIT_STATUS_LABELS[$status] ?? $status,
            'color' => self::PROFIT_STATUS_COLORS[$status] ?? 'light',
        ];
    }

    public static function paymentStatus(string $status): array
    {
        return [
            'label' => self::PAYMENT_STATUS_LABELS[$status] ?? $status,
            'color' => $status === 'paid' ? 'success' : 'light',
        ];
    }
}
