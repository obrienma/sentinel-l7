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
