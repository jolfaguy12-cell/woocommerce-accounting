export type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

// Known statuses get a friendly Persian label + a themed badge; anything
// unrecognized (new/unknown source statuses) still renders safely with its
// raw value instead of breaking the page (README §11: never hard-code and
// never let an unknown value break processing).
export const orderStatusLabels: Record<string, string> = {
    completed: 'تکمیل‌شده',
    processing: 'در حال پردازش',
    pending: 'در انتظار پرداخت',
    cancelled: 'لغوشده',
    refunded: 'مستردشده',
    trash: 'حذف‌شده',
    'auto-draft': 'پیش‌نویس',
    'bslm-completed': 'باسلام: تکمیل‌شده',
    'bslm-preparation': 'باسلام: در حال آماده‌سازی',
    'bslm-shipping': 'باسلام: ارسال‌شده',
    'bslm-wait-vendor': 'باسلام: در انتظار فروشنده',
    'bslm-rejected': 'باسلام: ردشده',
};

export const orderStatusVariant = (s: string): BadgeVariant =>
    s === 'completed' || s === 'bslm-completed'
        ? 'default'
        : s === 'cancelled' || s === 'bslm-rejected' || s === 'trash'
          ? 'destructive'
          : s === 'refunded'
            ? 'outline'
            : 'secondary';

export const financialStateLabels: Record<string, string> = {
    pending: 'در انتظار',
    valid: 'معتبر',
    refunded: 'مستردشده',
    partially_refunded: 'استرداد جزئی',
    cancelled: 'لغوشده',
    void: 'باطل',
};

export const financialStateVariant = (s: string): BadgeVariant =>
    s === 'valid' ? 'default' : s === 'cancelled' || s === 'void' ? 'destructive' : 'secondary';

export const profitStatusLabels: Record<string, string> = {
    ok: 'سود ثبت‌شده',
    blocked_missing_cost: 'مسدود — بدون بها',
    unknown_source: 'منبع ناشناخته',
    needs_review: 'نیازمند بازبینی',
    pending: 'در انتظار',
};

export const profitStatusVariant = (s: string): BadgeVariant =>
    s === 'ok' ? 'default' : s === 'blocked_missing_cost' || s === 'unknown_source' ? 'destructive' : 'secondary';

export const paymentStatusLabels: Record<string, string> = {
    paid: 'پرداخت‌شده',
    unpaid: 'پرداخت‌نشده',
};
