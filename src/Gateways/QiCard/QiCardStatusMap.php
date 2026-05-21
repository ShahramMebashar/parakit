<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\QiCard;

use Illuminate\Support\Facades\Log;
use Froshly\Parakit\Enums\PaymentStatus;

/**
 * Maps QiCard's `status` field (returned by /payment, /payment/{id}/status,
 * /cancel, and webhook bodies) to a canonical parakit PaymentStatus.
 *
 * QiCard exposes a wider state machine than parakit cares about — every
 * pre-terminal state (CREATED, FORM_SHOWED, AUTHENTICATION_*, STARTED, …)
 * collapses to Pending; only SUCCESS, FAILED, AUTHENTICATION_FAILED, ERROR
 * and EXPIRED are terminal.
 */
final class QiCardStatusMap
{
    public static function toStatus(string $status): PaymentStatus
    {
        return match (strtoupper(trim($status))) {
            'SUCCESS'                                                                                     => PaymentStatus::Paid,
            'FAILED', 'ERROR', 'AUTHENTICATION_FAILED'                                                    => PaymentStatus::Failed,
            'EXPIRED'                                                                                     => PaymentStatus::Expired,
            'CREATED', 'FORM_SHOWED', 'INITIALIZED', 'STARTED',
            'THREE_DS_METHOD_CALL_REQUIRED', 'AUTHENTICATION_REQUIRED',
            'AUTHENTICATION_STARTED', 'AUTHENTICATED'                                                     => PaymentStatus::Pending,
            default => self::warnUnknown($status),
        };
    }

    /**
     * Refund status mapper. QiCard returns one of SUCCESS / FAILED / PROCESSING.
     * Returns true on terminal success, false on terminal failure, null when
     * the refund is still in flight (caller should poll).
     */
    public static function refundOutcome(string $status): ?bool
    {
        return match (strtoupper(trim($status))) {
            'SUCCESS'    => true,
            'FAILED'     => false,
            'PROCESSING' => null,
            default      => self::warnUnknownRefund($status),
        };
    }

    private static function warnUnknown(string $status): PaymentStatus
    {
        Log::warning('parakit.qicard.unknown_status', ['status' => $status]);
        return PaymentStatus::Pending;
    }

    private static function warnUnknownRefund(string $status): null
    {
        Log::warning('parakit.qicard.unknown_refund_status', ['status' => $status]);
        return null;
    }
}
