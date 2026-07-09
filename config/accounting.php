<?php

return [
    // Divide hub/Woo amounts by this to get Toman. 1 = site amounts are Toman (confirmed).
    // If this ever changes, historical data must NOT be silently recalculated.
    'currency_divisor' => (int) env('ACC_CURRENCY_DIVISOR', 1),

    // Products at or below this stock count appear in the dashboard low-stock widget.
    'low_stock_threshold' => (int) env('ACC_LOW_STOCK_THRESHOLD', 3),
];
