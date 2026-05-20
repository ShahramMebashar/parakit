<?php
declare(strict_types=1);

return [
    'default' => env('PARAKIT_DEFAULT', 'fib'),

    'webhooks' => [
        'route_prefix' => 'payments/webhooks',
        'middleware' => ['api'],
        'tolerance_seconds' => 300,

        // Action when a Paid webhook's amount disagrees with the charged
        // amount: 'log' records a parakit.webhook.amount_mismatch warning and
        // still applies the transition; 'reject' refuses the transition.
        'on_amount_mismatch' => env('PARAKIT_WEBHOOK_ON_AMOUNT_MISMATCH', 'log'),
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
        'redact_keys' => ['password', 'token', 'secret', 'card', 'msisdn', 'authorization', 'transactionPin', 'mpin'],
        'retention_days' => 90,
    ],

    'sweeper' => [
        'enabled' => true,
        'older_than_minutes' => 5,
        'max_age_hours' => 24,
    ],

    'receipts' => [
        // Template used when none is given at runtime: modern | classic | minimal.
        'template' => env('PARAKIT_RECEIPTS_TEMPLATE', 'modern'),

        // Default disk + path for ReceiptDocument::save().
        'disk'     => env('PARAKIT_RECEIPTS_DISK', 'local'),
        'path'     => 'receipts',
        // Tokens: {type} {reference} {id}
        'filename' => '{type}-{reference}.pdf',

        // Locale the receipt is rendered in: 'app' uses the current app locale.
        'locale' => 'app',

        // Transaction metadata keys the receipt falls back to for customer
        // details when none are passed explicitly.
        'metadata' => [
            'name_key'   => 'customer_name',
            'email_key'  => 'customer_email',
            'phone_key'  => 'customer_phone',
            'locale_key' => 'locale',
        ],

        // Merchant block printed on every receipt.
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
                // DejaVu Sans is the only bundled dompdf font with Arabic /
                // Kurdish glyph coverage — required for ar/ckb receipts.
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
            // Full endpoint base — the path differs by environment:
            //   UAT  https://uatgw1.nasswallet.com/payment/transaction
            //   PROD https://gw-api.nasswallet.com/phase3/payment/transaction
            'base_url'        => env('NASSWALLET_BASE_URL', 'https://uatgw1.nasswallet.com/payment/transaction'),
            'portal_url'      => env('NASSWALLET_PORTAL_URL', 'https://uatcheckout1.nasswallet.com'),
            'basic_token'     => env('NASSWALLET_BASIC_TOKEN', 'TUVSQ0hBTlRfUEFZTUVOVF9HQVRFV0FZOk1lcmNoYW50R2F0ZXdheUBBZG1pbiMxMjM='),
            'username'        => env('NASSWALLET_USERNAME'),
            'password'        => env('NASSWALLET_PASSWORD'),
            'transaction_pin' => env('NASSWALLET_TRANSACTION_PIN'),
            'callback_url'    => env('NASSWALLET_CALLBACK_URL'),
        ],
        'fastpay' => [
            'driver'            => 'fastpay',
            'base_url'          => env('FASTPAY_BASE_URL', 'https://staging-pgw.fast-pay.iq'),
            'store_id'          => env('FASTPAY_STORE_ID'),
            'store_password'    => env('FASTPAY_STORE_PASSWORD'),
            'refund_secret_key' => env('FASTPAY_REFUND_SECRET_KEY'),
            'success_url'       => env('FASTPAY_SUCCESS_URL'),
            'cancel_url'        => env('FASTPAY_CANCEL_URL'),
            'callback_url'      => env('FASTPAY_CALLBACK_URL'),
        ],
    ],
];
