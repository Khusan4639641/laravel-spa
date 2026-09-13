<?php

return [
    'request_delay_min_ms' => max(0, (int) env('YANDEX_REQUEST_DELAY_MIN_MS', 1500)),
    'request_delay_max_ms' => max(0, (int) env('YANDEX_REQUEST_DELAY_MAX_MS', 4000)),
    'max_retries' => max(1, min(5, (int) env('YANDEX_MAX_RETRIES', 3))),
    'timeout_ms' => max(1000, (int) env('YANDEX_TIMEOUT_MS', 60000)),
    'total_timeout_ms' => max(5000, (int) env('YANDEX_TOTAL_TIMEOUT_MS', 300000)),
    'max_reviews' => max(1, min(2000, (int) env('YANDEX_MAX_REVIEWS', 600))),
    'max_scrolls' => max(1, min(500, (int) env('YANDEX_MAX_SCROLLS', 150))),
    'max_concurrent_jobs' => max(1, min(8, (int) env('YANDEX_MAX_CONCURRENT_JOBS', 2))),
    'node_binary' => env('YANDEX_NODE_BINARY', 'node'),
    'browser_path' => env('PLAYWRIGHT_BROWSERS_PATH', base_path('storage/app/playwright')),
    'chromium_sandbox' => (bool) env('YANDEX_CHROMIUM_SANDBOX', true),
    'user_agent' => env('YANDEX_USER_AGENT'),
    'queue' => 'yandex',
    // Same database as the domain tables: enqueue and run creation commit atomically.
    'queue_connection' => 'database',
];
