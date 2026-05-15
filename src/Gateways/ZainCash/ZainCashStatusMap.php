<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Illuminate\Support\Facades\Log;
use Froshly\Parakit\Enums\PaymentStatus;

final class ZainCashStatusMap
{
    private const MAP = [
        'SUCCESS'                          => PaymentStatus::Paid,
        'FAILED'                           => PaymentStatus::Failed,
        'PENDING'                          => PaymentStatus::Pending,
        'OTP_SENT'                         => PaymentStatus::Pending,
        'CUSTOMER_AUTHENTICATION_REQUIRED' => PaymentStatus::Pending,
        'EXPIRED'                          => PaymentStatus::Expired,
        'REFUNDED'                         => PaymentStatus::Refunded,
    ];

    public static function toStatus(string $raw): PaymentStatus
    {
        $upper = strtoupper($raw);
        if (isset(self::MAP[$upper])) {
            return self::MAP[$upper];
        }
        // Surface ZainCash API drift: a new status string should be visible to
        // operators before every transaction silently becomes Pending.
        Log::warning('parakit.zaincash.unknown_status', ['raw' => $raw]);
        return PaymentStatus::Pending;
    }
}
