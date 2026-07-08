<?php

return [
    // Read-only data API of the mirror hub. NEVER point this at the production WooCommerce site.
    'base_url' => env('HUB_BASE_URL', 'https://hub.behdashtik.ir/api/v1'),
    'api_key' => env('HUB_API_KEY'),

    // Secret registered alongside our endpoint in the hub's webhook_endpoints table.
    'webhook_secret' => env('HUB_WEBHOOK_SECRET'),
    'webhook_max_attempts' => (int) env('HUB_WEBHOOK_MAX_ATTEMPTS', 3),

    // Poll fallback: re-fetch a safety window behind the stored cursor (mirror swap protection).
    'poll_overlap_minutes' => (int) env('HUB_POLL_OVERLAP_MINUTES', 10),

    'timeout' => (int) env('HUB_TIMEOUT', 20),
];
