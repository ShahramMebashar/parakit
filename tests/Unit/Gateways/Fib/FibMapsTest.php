<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\Fib\FibErrorMap;
use Froshly\Parakit\Gateways\Fib\FibStatusMap;
use Froshly\Parakit\Enums\PaymentErrorCode;
use Froshly\Parakit\Enums\PaymentStatus;

it('maps known FIB statuses to PaymentStatus', function () {
    expect(FibStatusMap::toStatus('UNPAID'))->toBe(PaymentStatus::Pending)
        ->and(FibStatusMap::toStatus('PAID'))->toBe(PaymentStatus::Paid)
        ->and(FibStatusMap::toStatus('DECLINED'))->toBe(PaymentStatus::Failed)
        ->and(FibStatusMap::toStatus('EXPIRED'))->toBe(PaymentStatus::Expired)
        ->and(FibStatusMap::toStatus('REFUND_REQUESTED'))->toBe(PaymentStatus::Refunded);
});

it('falls back to Pending for unknown statuses', function () {
    expect(FibStatusMap::toStatus('WAT'))->toBe(PaymentStatus::Pending);
});

it('maps FIB error codes to PaymentErrorCode', function () {
    expect(FibErrorMap::toCode('insufficient_funds'))->toBe(PaymentErrorCode::InsufficientFunds)
        ->and(FibErrorMap::toCode('invalid_amount'))->toBe(PaymentErrorCode::InvalidAmount)
        ->and(FibErrorMap::toCode('expired'))->toBe(PaymentErrorCode::Expired);
});

it('falls back to Unknown for unmapped codes', function () {
    expect(FibErrorMap::toCode('totally_new_thing'))->toBe(PaymentErrorCode::Unknown);
});
