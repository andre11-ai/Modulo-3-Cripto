<?php

return [
    'use_mocks' => filter_var(env('M3_USE_MOCKS', true), FILTER_VALIDATE_BOOLEAN),

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'services' => [
        'm1_jwks_url' => env('M1_JWKS_URL', 'http://localhost:8001/.well-known/jwks.json'),
        'm2_api_url' => env('M2_API_URL', 'http://localhost:8002/api'),
        'm4_api_url' => env('M4_API_URL', 'http://localhost:8004/api'),
    ],
];
