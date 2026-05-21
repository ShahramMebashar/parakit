<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\QiCard;

use Froshly\Parakit\Enums\PaymentErrorCode;

/**
 * Maps a QiCard numeric error code (1–35) returned in `error.code` to a
 * canonical PaymentErrorCode. Codes with no clean fit fall through to
 * Unknown so the raw QiCard code and message remain visible to callers via
 * PaymentError.rawCode / rawMessage.
 */
final class QiCardErrorMap
{
    public static function toCode(int $apiCode): PaymentErrorCode
    {
        return match ($apiCode) {
            // 27 BAD_CREDENTIALS · 9 TERMINAL_NOT_FOUND_EXCEPTION
            27, 9        => PaymentErrorCode::InvalidCredentials,

            // 28 LIMIT_VIOLATION — over/under merchant limits
            28           => PaymentErrorCode::InvalidAmount,

            // 1 ORDER_ALREADY_EXISTS · 7 REQUISITES_ALREADY_EXISTS
            // 10 PAYMENT_ALREADY_EXISTS · 34 TRANSFER_ALREADY_EXISTS
            1, 7, 10, 34 => PaymentErrorCode::DuplicateTransaction,

            // 3 ORDER_ALREADY_CANCELLED — the payer's order is gone; the
            // closest canonical signal is "user cancelled".
            3            => PaymentErrorCode::UserCancelled,

            // 14 PROCESSING_IMPOSSIBLE · 23 INTERNAL_SYSTEM_ERROR · 24 EXTERNAL_SYSTEM_ERROR
            14, 23, 24   => PaymentErrorCode::GatewayUnavailable,

            // 21 VALIDATION_ERROR · 19 PAYMENT_PARAMS_NOT_FOUND — usually amount /
            // currency / required-field issues. Surface as InvalidAmount; the
            // rawMessage stays visible for ambiguous cases.
            19, 21       => PaymentErrorCode::InvalidAmount,

            // Everything else carries enough detail in rawMessage that an
            // explicit canonical mapping would just lose information:
            // 2, 4, 5, 6, 8, 11, 12, 13, 15, 16, 17, 18, 20, 22, 26,
            // 29, 30, 31, 32, 33, 35.
            default      => PaymentErrorCode::Unknown,
        };
    }
}
