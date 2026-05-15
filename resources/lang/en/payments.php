<?php
declare(strict_types=1);

return [
    'errors' => [
        'insufficient_funds'    => 'Insufficient funds.',
        'invalid_phone'         => 'The phone number is invalid.',
        'user_cancelled'        => 'You cancelled the payment.',
        'expired'               => 'The payment has expired.',
        'invalid_amount'        => 'The amount is invalid.',
        'invalid_credentials'   => 'Gateway credentials are invalid.',
        'gateway_unavailable'   => 'The payment gateway is unavailable. Please try again.',
        'duplicate_transaction' => 'This transaction was already submitted.',
        'network_error'         => 'A network error occurred. Please try again.',
        'timeout'               => 'The payment timed out. Please try again.',
        'signature_invalid'     => 'Invalid signature.',
        'unknown'               => 'An unknown error occurred.',
    ],
    'statuses' => [
        'pending'            => 'Pending',
        'processing'         => 'Processing',
        'paid'               => 'Paid',
        'failed'             => 'Failed',
        'cancelled'          => 'Cancelled',
        'refunded'           => 'Refunded',
        'partially_refunded' => 'Partially refunded',
        'expired'            => 'Expired',
        'disputed'           => 'Disputed',
    ],
    'ui' => [
        'pay_with_fib'       => 'Pay with FIB',
        'pay_with_zaincash'  => 'Pay with ZainCash',
        'redirecting'        => 'Redirecting to gateway…',
        'payment_pending'    => 'Your payment is being processed.',
    ],
];
