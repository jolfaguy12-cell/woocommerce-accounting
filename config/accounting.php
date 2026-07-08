<?php

return [
    // Divide hub/Woo amounts by this to get Toman. 1 = site amounts are Toman (confirmed).
    // If this ever changes, historical data must NOT be silently recalculated.
    'currency_divisor' => (int) env('ACC_CURRENCY_DIVISOR', 1),
];
