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
            'callback_url' => env('FIB_CALLBACK_URL'),
        ],
        'zaincash' => [
            'driver' => 'zaincash',
            'base_url' => env('ZAINCASH_BASE_URL', 'https://test.zaincash.iq'),
            'merchant_id' => env('ZAINCASH_MERCHANT_ID'),
            'msisdn' => env('ZAINCASH_MSISDN'),
            'secret' => env('ZAINCASH_SECRET'),
            'lang' => env('ZAINCASH_LANG', 'en'),
            'redirect_url' => env('ZAINCASH_REDIRECT_URL'),
        ],
    ],
];
