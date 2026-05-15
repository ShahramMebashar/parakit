<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\ZainCash\ZainCashStatusMap;
use Froshly\Parakit\Enums\PaymentStatus;

it('maps every documented v2 status string', function () {
    expect(ZainCashStatusMap::toStatus('SUCCESS'))->toBe(PaymentStatus::Paid)
        ->and(ZainCashStatusMap::toStatus('FAILED'))->toBe(PaymentStatus::Failed)
        ->and(ZainCashStatusMap::toStatus('PENDING'))->toBe(PaymentStatus::Pending)
        ->and(ZainCashStatusMap::toStatus('OTP_SENT'))->toBe(PaymentStatus::Pending)
        ->and(ZainCashStatusMap::toStatus('CUSTOMER_AUTHENTICATION_REQUIRED'))->toBe(PaymentStatus::Pending)
        ->and(ZainCashStatusMap::toStatus('EXPIRED'))->toBe(PaymentStatus::Expired)
        ->and(ZainCashStatusMap::toStatus('REFUNDED'))->toBe(PaymentStatus::Refunded);
});

it('is case-insensitive', function () {
    expect(ZainCashStatusMap::toStatus('success'))->toBe(PaymentStatus::Paid);
});

it('falls back to Pending for unknown status strings', function () {
    expect(ZainCashStatusMap::toStatus('SOMETHING_NEW'))->toBe(PaymentStatus::Pending);
});
