<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Fib;

use Froshly\Parakit\Enums\PaymentErrorCode;

final class FibErrorMap
{
    private const MAP = [
        'insufficient_funds' => PaymentErrorCode::InsufficientFunds,
        'invalid_amount'     => PaymentErrorCode::InvalidAmount,
        'invalid_phone'      => PaymentErrorCode::InvalidPhone,
        'expired'            => PaymentErrorCode::Expired,
        'user_cancelled'     => PaymentErrorCode::UserCancelled,
        'unauthorized'       => PaymentErrorCode::InvalidCredentials,
        'duplicate'          => PaymentErrorCode::DuplicateTransaction,
        'timeout'            => PaymentErrorCode::Timeout,
    ];

    public static function toCode(string $raw): PaymentErrorCode
    {
        return self::MAP[strtolower($raw)] ?? PaymentErrorCode::Unknown;
    }
}
