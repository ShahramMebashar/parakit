<?php
declare(strict_types=1);

namespace Gutian\Parakit\Gateways\ZainCash;

use Gutian\Parakit\Enums\PaymentErrorCode;

final class ZainCashErrorMap
{
    public static function toCode(string $raw): PaymentErrorCode
    {
        $r = strtolower($raw);
        return match (true) {
            str_contains($r, 'insufficient') => PaymentErrorCode::InsufficientFunds,
            str_contains($r, 'cancel')       => PaymentErrorCode::UserCancelled,
            str_contains($r, 'expire')       => PaymentErrorCode::Expired,
            str_contains($r, 'invalid')      => PaymentErrorCode::InvalidAmount,
            str_contains($r, 'timeout')      => PaymentErrorCode::Timeout,
            default                          => PaymentErrorCode::Unknown,
        };
    }
}
