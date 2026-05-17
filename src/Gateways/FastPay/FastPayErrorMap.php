<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\FastPay;

use Froshly\Parakit\Enums\PaymentErrorCode;

/**
 * Maps a FastPay error message (the first entry of the `messages` array) to a
 * canonical PaymentErrorCode. FastPay returns no machine error codes, so the
 * match is done on substrings of the human-readable message.
 */
final class FastPayErrorMap
{
    public static function toCode(string $raw): PaymentErrorCode
    {
        $r = strtolower($raw);

        return match (true) {
            str_contains($r, 'store id'),
            str_contains($r, 'store password'),
            str_contains($r, 'secret key')          => PaymentErrorCode::InvalidCredentials,
            str_contains($r, 'already refunded')    => PaymentErrorCode::DuplicateTransaction,
            str_contains($r, 'amount')              => PaymentErrorCode::InvalidAmount,
            default                                 => PaymentErrorCode::Unknown,
        };
    }
}
