<?php
declare(strict_types=1);

return [
    'default' => env('PARAKIT_DEFAULT', 'fib'),

    'webhooks' => [
        'route_prefix' => 'payments/webhooks',
        'middleware' => ['api'],
        'tolerance_seconds' => 300,
    ],

    'reliability' => [
        'idempotency_ttl' => 86400,
        'retry' => ['max_attempts' => 3, 'base_delay_ms' => 200],
        'circuit_breaker' => ['failure_threshold' => 5, 'cooldown_seconds' => 30],
        'timeout_seconds' => 15,
    ],

    'logging' => [
        'enabled' => true,
        'channel' => env('PARAKIT_LOG_CHANNEL', 'stack'),
        'redact_keys' => ['password', 'token', 'secret', 'card', 'msisdn', 'authorization'],
        'retention_days' => 90,
    ],

    'sweeper' => [
        'enabled' => true,
        'older_than_minutes' => 5,
        'max_age_hours' => 24,
    ],

    'gateways' => [
        'fib' => [
            'driver' => 'fib',
            'base_url' => env('FIB_BASE_URL', 'https://fib.stage.fib.iq'),
            'client_id' => env('FIB_CLIENT_ID'),
            'client_secret' => env('FIB_CLIENT_SECRET'),
            'currency' => env('FIB_CURRENCY', 'IQD'),
            'refundable_for' => env('FIB_REFUNDABLE_FOR', 'P7D'),
            'expires_in' => env('FIB_EXPIRES_IN'),
            'category' => env('FIB_CATEGORY'),
            'callback_url' => env('FIB_CALLBACK_URL'),
        ],
        'zaincash' => [
            'driver'        => 'zaincash',
            'base_url'      => env('ZAINCASH_BASE_URL', 'https://pg-api-uat.zaincash.iq'),
            'client_id'     => env('ZAINCASH_CLIENT_ID'),
            'client_secret' => env('ZAINCASH_CLIENT_SECRET'),
            'api_key'       => env('ZAINCASH_API_KEY'),
            'scope'         => env('ZAINCASH_SCOPE', 'payment:read payment:write reverse:write'),
            'service_type'  => env('ZAINCASH_SERVICE_TYPE', 'Delivery'),
            'lang'          => env('ZAINCASH_LANG', 'en'),
            'success_url'   => env('ZAINCASH_SUCCESS_URL'),
            'failure_url'   => env('ZAINCASH_FAILURE_URL'),
        ],
        'nass' => [
            'driver'           => 'nass',
            'base_url'         => env('NASS_BASE_URL', 'https://uat-gateway.nass.iq:9746'),
            'username'         => env('NASS_USERNAME'),
            'password'         => env('NASS_PASSWORD'),
            'token_ttl'        => (int) env('NASS_TOKEN_TTL', 3000),
            'transaction_type' => (int) env('NASS_TRANSACTION_TYPE', 1),
            'callback_url'     => env('NASS_CALLBACK_URL'),
            'return_url'       => env('NASS_RETURN_URL'),
        ],
    ],
];
