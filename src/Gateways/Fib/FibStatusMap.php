<?php
declare(strict_types=1);

namespace Gutian\Parakit\Gateways\Fib;

use Illuminate\Support\Facades\Log;
use Gutian\Parakit\Enums\PaymentStatus;

final class FibStatusMap
{
    private const MAP = [
        'UNPAID'             => PaymentStatus::Pending,
        'PAID'               => PaymentStatus::Paid,
        'DECLINED'           => PaymentStatus::Failed,
        'EXPIRED'            => PaymentStatus::Expired,
        'REFUND_REQUESTED'   => PaymentStatus::Refunded,
        'REFUNDED'           => PaymentStatus::Refunded,
        'CANCELLED'          => PaymentStatus::Cancelled,
    ];

    public static function toStatus(string $raw): PaymentStatus
    {
        $upper = strtoupper($raw);
        if (isset(self::MAP[$upper])) {
            return self::MAP[$upper];
        }
        // Surface FIB API drift: if a new status string appears, log it so
        // operators see it before every paid txn silently becomes Pending.
        Log::warning('parakit.fib.unknown_status', ['raw' => $raw]);
        return PaymentStatus::Pending;
    }
}
