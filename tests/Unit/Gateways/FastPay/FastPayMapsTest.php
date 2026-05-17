<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\FastPay\FastPayStatusMap;
use Froshly\Parakit\Gateways\FastPay\FastPayErrorMap;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\PaymentErrorCode;

it('maps a Success validate status to Paid', function () {
    expect(FastPayStatusMap::toStatus('Success'))->toBe(PaymentStatus::Paid)
        ->and(FastPayStatusMap::toStatus('SUCCESS'))->toBe(PaymentStatus::Paid)
        ->and(FastPayStatusMap::toStatus('success'))->toBe(PaymentStatus::Paid);
});

it('falls back to Pending for an unrecognised validate status', function () {
    expect(FastPayStatusMap::toStatus(''))->toBe(PaymentStatus::Pending)
        ->and(FastPayStatusMap::toStatus('Whatever'))->toBe(PaymentStatus::Pending);
});

it('maps FastPay error messages to PaymentErrorCode', function () {
    expect(FastPayErrorMap::toCode('Sorry! The Store ID and Store Password combination is wrong.'))
        ->toBe(PaymentErrorCode::InvalidCredentials)
        ->and(FastPayErrorMap::toCode('Invalid secret key given.'))
        ->toBe(PaymentErrorCode::InvalidCredentials)
        ->and(FastPayErrorMap::toCode('The transaction is already refunded'))
        ->toBe(PaymentErrorCode::DuplicateTransaction)
        ->and(FastPayErrorMap::toCode('Refund amount can not be greater than transaction amount!'))
        ->toBe(PaymentErrorCode::InvalidAmount);
});

it('falls back to Unknown for an unmapped error message', function () {
    expect(FastPayErrorMap::toCode('Sorry! No transaction has been found against your Order ID.'))
        ->toBe(PaymentErrorCode::Unknown)
        ->and(FastPayErrorMap::toCode(''))->toBe(PaymentErrorCode::Unknown);
});
