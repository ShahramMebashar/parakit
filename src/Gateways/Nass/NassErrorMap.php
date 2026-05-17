<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Nass;

use Froshly\Parakit\Enums\PaymentErrorCode;

final class NassErrorMap
{
    /**
     * NassPay transaction `responseCode` => Parakit PaymentErrorCode.
     * PaymentErrorCode has no card-decline / 3DS-auth case, so most negative
     * codes honestly map to Unknown; the raw code stays in PaymentResponse::raw.
     */
    private const MAP = [
        '-10' => PaymentErrorCode::InvalidAmount,        // amount field error
        '-20' => PaymentErrorCode::Timeout,              // timestamp window exceeded
        '-21' => PaymentErrorCode::DuplicateTransaction, // already executed
        '-25' => PaymentErrorCode::UserCancelled,        // cancelled by user
        '-29' => PaymentErrorCode::DuplicateTransaction, // duplicate auth reference
        '-31' => PaymentErrorCode::DuplicateTransaction, // already in progress
        '-32' => PaymentErrorCode::DuplicateTransaction, // repeated declined txn
    ];

    public static function toCode(string $responseCode): PaymentErrorCode
    {
        return self::MAP[trim($responseCode)] ?? PaymentErrorCode::Unknown;
    }
}
