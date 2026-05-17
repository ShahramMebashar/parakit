<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Nass;

use Illuminate\Support\Facades\Log;
use Froshly\Parakit\Enums\PaymentStatus;

final class NassStatusMap
{
    private const SUCCESS = '00';
    private const CANCELLED = '-25';

    /** Negative codes that mean "still processing" — not a final failure. */
    private const IN_PROGRESS = ['-33', '-39', '-40', '-47'];

    public static function toStatus(string $responseCode): PaymentStatus
    {
        $code = trim($responseCode);

        if ($code === self::SUCCESS) {
            return PaymentStatus::Paid;
        }
        if ($code === self::CANCELLED) {
            return PaymentStatus::Cancelled;
        }
        if (in_array($code, self::IN_PROGRESS, true)) {
            return PaymentStatus::Pending;
        }
        if (str_starts_with($code, '-')) {
            return PaymentStatus::Failed;
        }

        // Surface API drift: an unrecognised code should be visible to
        // operators rather than silently treated as paid or failed.
        Log::warning('parakit.nass.unknown_status', ['responseCode' => $responseCode]);
        return PaymentStatus::Pending;
    }
}
