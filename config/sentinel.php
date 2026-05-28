<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Simulation Settings
    |--------------------------------------------------------------------------
    | These values control the Sentinel-L7 transaction stream generator.
    */

    'thresholds' => [
        'high_risk' => 400.00,
    ],

    'ai_driver'       => env('SENTINEL_AI_DRIVER', 'gemini'),
    'axiom_threshold' => env('AXIOM_AUDIT_THRESHOLD', 0.8),

    'backpressure' => [
        'publish_pause_threshold' => env('SENTINEL_PUBLISH_PAUSE_THRESHOLD', 800),
        'publish_pause_ms'        => env('SENTINEL_PUBLISH_PAUSE_MS', 500),
        // Graduated lag thresholds (XPENDING count). See ADR-0023.
        'lag_warn'                => env('SENTINEL_LAG_WARN', 50),
        'lag_pause'               => env('SENTINEL_LAG_PAUSE', 200),
        'lag_warn_sleep_ms'       => env('SENTINEL_LAG_WARN_SLEEP_MS', 500),
        'lag_pause_poll_ms'       => env('SENTINEL_LAG_PAUSE_POLL_MS', 100),
    ],

    'rate_limits' => [
        'login'     => ['attempts' => env('RATE_LIMIT_LOGIN', 5),     'decay' => 'perMinute'],
        'signup'    => ['attempts' => env('RATE_LIMIT_SIGNUP', 10),   'decay' => 'perHour'],
        'ai_stream' => ['attempts' => env('RATE_LIMIT_AI_STREAM', 20), 'decay' => 'perMinute'],
    ],

    'reclaim' => [
        // Min idle (ms) before an in-flight message can be stolen by XAUTOCLAIM.
        // See ADR-0022.
        'idle_ms'              => env('SENTINEL_RECLAIM_IDLE_MS', 30_000),
        'delivery_count_limit' => env('SENTINEL_RECLAIM_DELIVERY_LIMIT', 3),
    ],

    'simulation' => [
        'merchants' => [
            'Costco',
            "Kim's Farm Market",
            'Blendz',
            'Sandwich.net',
            'Rio Friendly Meats',
            'London Drugs'
        ],
        'currencies' => [
            'CAD',
            'EUR',
            'GBP',
            'JPY'
        ],
    ],
];
