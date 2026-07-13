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
        '9999', // حساب تعدیل رند کردن — classified adjustment
    ],
];
