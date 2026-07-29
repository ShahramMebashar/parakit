<?php
declare(strict_types=1);

return [
    'default' => env('PARAKIT_DEFAULT', 'fib'),

    'webhooks' => [
        'route_prefix' => 'payments/webhooks',
        'middleware' => ['api'],
        'tolerance_seconds' => 300,

        // 'log' warns and still applies; 'reject' refuses the transition.
        // Any other value falls back to 'log' (parakit:doctor flags it).
        'on_amount_mismatch' => env('PARAKIT_WEBHOOK_ON_AMOUNT_MISMATCH', 'log'),

        // Scheduled replay catches orphan events whose gateway stopped retrying.
        'replay' => [
            'enabled' => env('PARAKIT_WEBHOOK_REPLAY_ENABLED', true),
            'older_than_minutes' => 5,
        ],
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
        // Entries are word-component tokens matched against camelCase / snake_case key parts,
        // so 'secret' covers client_secret/apiSecret without sweeping 'secretary'.
        'redact_keys' => ['password', 'token', 'secret', 'key', 'card', 'cvv', 'cvc', 'pan', 'msisdn', 'authorization', 'pin', 'mpin'],
        'retention_days' => 90,
    ],

    'raw_payloads' => [
        // Runtime PaymentResponse/RefundResponse objects keep the raw gateway body.
        // Anything Parakit stores in its own DB rows/cache goes through this policy.
        'store' => env('PARAKIT_STORE_RAW_PAYLOADS', true),
        'redact' => env('PARAKIT_REDACT_RAW_PAYLOADS', true),
    ],

    'sweeper' => [
        'enabled' => true,
        'older_than_minutes' => 5,
        'max_age_hours' => 24,
    ],

    'receipts' => [
        // modern | classic | minimal
        'template' => env('PARAKIT_RECEIPTS_TEMPLATE', 'modern'),

        'disk'     => env('PARAKIT_RECEIPTS_DISK', 'local'),
        'path'     => 'receipts',
        // Tokens: {type} {reference} {id}
        'filename' => '{type}-{reference}.pdf',

        // 'app' uses the current app locale.
        'locale' => 'app',

        'metadata' => [
            'name_key'   => 'customer_name',
            'email_key'  => 'customer_email',
            'phone_key'  => 'customer_phone',
            'locale_key' => 'locale',
        ],

        'merchant' => [
            'name'          => env('PARAKIT_MERCHANT_NAME', config('app.name')),
            'address'       => env('PARAKIT_MERCHANT_ADDRESS'),
            'logo'          => env('PARAKIT_MERCHANT_LOGO'), // absolute path or data URI
            'support_email' => env('PARAKIT_MERCHANT_SUPPORT_EMAIL'),
        ],

        'pdf' => [
            'paper'       => 'a4',
            'orientation' => 'portrait',
            'options'     => [
                // DejaVu Sans is the only bundled dompdf font with Arabic/Kurdish coverage.
                'defaultFont'      => 'DejaVu Sans',
                'isRemoteEnabled'  => false,
            ],
        ],
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
        'nasswallet' => [
            'driver'          => 'nasswallet',
            // Path differs by env: UAT /payment/transaction, PROD /phase3/payment/transaction.
            'base_url'        => env('NASSWALLET_BASE_URL', 'https://uatgw1.nasswallet.com/payment/transaction'),
            'portal_url'      => env('NASSWALLET_PORTAL_URL', 'https://uatcheckout1.nasswallet.com'),
            'basic_token'     => env('NASSWALLET_BASIC_TOKEN'),
            'username'        => env('NASSWALLET_USERNAME'),
            'password'        => env('NASSWALLET_PASSWORD'),
            'transaction_pin' => env('NASSWALLET_TRANSACTION_PIN'),
            'callback_url'    => env('NASSWALLET_CALLBACK_URL'),
        ],
        'fastpay' => [
            'driver'            => 'fastpay',
            'base_url'          => env('FASTPAY_BASE_URL', 'https://staging-apigw-merchant.fast-pay.iq'),
            'store_id'          => env('FASTPAY_STORE_ID'),
            'store_password'    => env('FASTPAY_STORE_PASSWORD'),
            'refund_secret_key' => env('FASTPAY_REFUND_SECRET_KEY'),
            'success_url'       => env('FASTPAY_SUCCESS_URL'),
            'cancel_url'        => env('FASTPAY_CANCEL_URL'),
            'callback_url'      => env('FASTPAY_CALLBACK_URL'),
        ],
        'qicard' => [
            'driver'             => 'qicard',
            'base_url'           => env('QICARD_BASE_URL', 'https://uat-sandbox-3ds-api.qi.iq'),
            'username'           => env('QICARD_USERNAME'),
            'password'           => env('QICARD_PASSWORD'),
            'terminal_id'        => env('QICARD_TERMINAL_ID'),
            'locale'             => env('QICARD_LOCALE', 'en_US'),
            // RSA-2048 PEM verifying webhook X-Signature; when unset, parakit falls back to a
            // server-to-server status re-check and logs `parakit.qicard.webhook.unverified`.
            'public_key'         => env('QICARD_PUBLIC_KEY'),
            'finish_payment_url' => env('QICARD_FINISH_PAYMENT_URL'),
            'notification_url'   => env('QICARD_NOTIFICATION_URL'),
        ],
    ],
];
