<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\NassWallet;

use Illuminate\Support\Facades\Log;
use Froshly\Parakit\Enums\PaymentStatus;

/**
 * Maps the `transactionStatus` string returned by NassWallet's
 * checkTransaction endpoint to a canonical PaymentStatus.
 */
final class NassWalletStatusMap
{
    private const MAP = [
        'SUCCESS' => PaymentStatus::Paid,
        'FAILED'  => PaymentStatus::Failed,
    ];

    public static function toStatus(string $status): PaymentStatus
    {
        $key = strtoupper(trim($status));

        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }

        // Surface API drift: an unrecognised status should be visible to
        // operators rather than silently treated as paid or failed.
        Log::warning('parakit.nasswallet.unknown_status', ['status' => $status]);
        return PaymentStatus::Pending;
    }
}
