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
