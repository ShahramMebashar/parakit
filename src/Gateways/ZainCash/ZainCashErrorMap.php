<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Froshly\Parakit\Enums\PaymentErrorCode;

final class ZainCashErrorMap
{
    public static function toCode(string $raw): PaymentErrorCode
    {
        $r = strtolower($raw);

        return match (true) {
            str_contains($r, 'unauthorized')  => PaymentErrorCode::InvalidCredentials,
            str_contains($r, 'insufficient')  => PaymentErrorCode::InsufficientFunds,
            str_contains($r, 'cancel')        => PaymentErrorCode::UserCancelled,
            str_contains($r, 'expire')        => PaymentErrorCode::Expired,
            str_contains($r, 'timeout')       => PaymentErrorCode::Timeout,
            str_contains($r, 'phone')         => PaymentErrorCode::InvalidPhone,
            str_contains($r, 'amount')        => PaymentErrorCode::InvalidAmount,
            default                           => PaymentErrorCode::Unknown,
        };
    }
}
