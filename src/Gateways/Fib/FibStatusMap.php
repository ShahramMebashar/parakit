<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Fib;

use Illuminate\Support\Facades\Log;
use Froshly\Parakit\Enums\PaymentStatus;

final class FibStatusMap
{
    private const MAP = [
        'UNPAID'             => PaymentStatus::Pending,
        'PAID'               => PaymentStatus::Paid,
        'DECLINED'           => PaymentStatus::Failed,
        'EXPIRED'            => PaymentStatus::Expired,
        // The money has not been returned until FIB reaches REFUNDED.
        'REFUND_REQUESTED'   => PaymentStatus::Paid,
        'REFUNDED'           => PaymentStatus::Refunded,
        'CANCELLED'          => PaymentStatus::Cancelled,
    ];

    public static function toStatus(string $raw, ?string $decliningReason = null): PaymentStatus
    {
        $upper = strtoupper($raw);
        if ($upper === 'DECLINED') {
            return match (strtoupper((string) $decliningReason)) {
                'PAYMENT_EXPIRATION' => PaymentStatus::Expired,
                'PAYMENT_CANCELLATION' => PaymentStatus::Cancelled,
                default => PaymentStatus::Failed,
            };
        }
        if (isset(self::MAP[$upper])) {
            return self::MAP[$upper];
        }
        // Surface FIB API drift: if a new status string appears, log it so
        // operators see it before every paid txn silently becomes Pending.
        Log::warning('parakit.fib.unknown_status', ['raw' => $raw]);
        return PaymentStatus::Pending;
    }
}
