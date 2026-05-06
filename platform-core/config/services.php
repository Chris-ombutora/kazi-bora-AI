<?php

/**
 * External microservice and API configuration.
 * All URLs and credentials for the services KaziBora Platform Core connects to.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | NLP Parsing Service (Developer 1's FastAPI)
    |--------------------------------------------------------------------------
    */
    'nlp' => [
        'base_url' => env('NLP_SERVICE_URL', 'http://nlp-service:8000'),
        'timeout' => 30,
        'retry_times' => 3,
        'retry_delay_ms' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Matching Service (Developer 2's FastAPI)
    |--------------------------------------------------------------------------
    */
    'matcher' => [
        'base_url' => env('MATCHER_SERVICE_URL', 'http://matcher-api:8001'),
        'timeout' => 60,
        'retry_times' => 3,
        'retry_delay_ms' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT Authentication
    |--------------------------------------------------------------------------
    */
    'jwt' => [
        'secret' => env('JWT_SECRET', 'change-me-in-production'),
        'ttl' => env('JWT_TTL', 1440), // minutes (default 24 hours)
        'algorithm' => 'HS256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Safaricom M-Pesa Daraja API
    |--------------------------------------------------------------------------
    */
    'mpesa' => [
        'consumer_key' => env('MPESA_CONSUMER_KEY', ''),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET', ''),
        'passkey' => env('MPESA_PASSKEY', ''),
        'shortcode' => env('MPESA_SHORTCODE', '174379'),
        'env' => env('MPESA_ENV', 'sandbox'), // 'sandbox' or 'production'
        'callback_url' => env('MPESA_CALLBACK_URL', ''),
        'urls' => [
            'sandbox' => [
                'oauth' => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
                'stk_push' => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
                'stk_query' => 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            ],
            'production' => [
                'oauth' => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
                'stk_push' => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
                'stk_query' => 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query',
            ],
        ],
    ],
];
