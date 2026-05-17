<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\FastPay;

use Illuminate\Support\Facades\Log;
use Froshly\Parakit\Enums\PaymentStatus;

/**
 * Maps the `data.status` string returned by FastPay's payment/validate
 * endpoint to a canonical PaymentStatus.
 *
 * FastPay validate only ever reports "Success" for a paid order; an unpaid
 * order returns HTTP code 404 instead of a distinct status, so the gateway
 * maps the not-found case to Pending directly.
 */
final class FastPayStatusMap
{
    public static function toStatus(string $status): PaymentStatus
    {
        if (strtoupper(trim($status)) === 'SUCCESS') {
            return PaymentStatus::Paid;
        }

        // Surface API drift: an unrecognised status should be visible to
        // operators rather than silently treated as paid or failed.
        Log::warning('parakit.fastpay.unknown_status', ['status' => $status]);
        return PaymentStatus::Pending;
    }
}
