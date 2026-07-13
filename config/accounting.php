<?php

return [
    // Divide hub/Woo amounts by this to get Toman. 1 = site amounts are Toman (confirmed).
    // If this ever changes, historical data must NOT be silently recalculated.
    'currency_divisor' => (int) env('ACC_CURRENCY_DIVISOR', 1),

    // Products at or below this stock count appear in the dashboard low-stock widget.
    'low_stock_threshold' => (int) env('ACC_LOW_STOCK_THRESHOLD', 3),

    /*
    |--------------------------------------------------------------------------
    | Counter-accounts a DIRECT deposit/withdrawal may touch
    |--------------------------------------------------------------------------
    |
    | An allowlist, not a blocklist. A direct account operation is the generic
    | escape hatch of the system, and an escape hatch that can reach a control
    | account is a way to bypass every workflow built on top of it: a receivable
    | settled without a payment record, a payable cleared without a supplier,
    | payroll paid without a payroll run. Each of those accounts has a typed
    | workflow that keeps its subsidiary ledger and its journal in step, and the
    | ONLY way to move them is through it.
    |
    | So direct operations may touch nothing but genuine income, genuine expense
    | and explicitly-classified adjustments — the movements that have no other
    | home. Everything else, including every account listed in
    | CounterAccountPolicy::CONTROL_ACCOUNTS, is unreachable from here.
    |
    | Adding a code here is an accounting decision: it must be an account that no
    | other workflow owns.
    |
    */
    'direct_operation_counter_accounts' => [
        '4900', // سایر درآمدها — direct income; before Commit 4 nothing could post to it at all
        '6000', // هزینه‌های عملیاتی — uncategorised operating expense (a CATEGORISED one goes through ExpenseRecorder)
        '6350', // کارمزد بانکی
        '6370', // جریمه دیرکرد
        '9999', // حساب تعدیل — allowlisted, but restricted further below
    ],

    /*
    |--------------------------------------------------------------------------
    | Adjustment accounts — allowlisted, but never routine
    |--------------------------------------------------------------------------
    |
    | An adjustment account is the one counter-account that does not claim the
    | money went anywhere: it says "the books were wrong, and this makes them
    | right". That is occasionally necessary and always suspicious — it is the
    | single line an operator would reach for to make an unexplained difference
    | disappear, and every unexplained difference it absorbs is a reconciliation
    | that will now never happen.
    |
    | So it is allowlisted (it must be reachable — rounding differences are real)
    | but fenced: admins only, an explicit written reason, an external reference,
    | and a second person's approval before it ever reaches the ledger, whatever
    | the amount and whatever `ops.approval_threshold` says. An accountant never
    | sees it in the dropdown at all.
    |
    */
    'adjustment_counter_accounts' => [
        '9999', // حساب تعدیل رند کردن
    ],

    // Who may use an adjustment account at all.
    'adjustment_account_roles' => ['admin'],
];
