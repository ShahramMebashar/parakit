<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\ZainCash\ZainCashErrorMap;
use Froshly\Parakit\Enums\PaymentErrorCode;

it('maps v2 error codes', function () {
    expect(ZainCashErrorMap::toCode('PAYMENT_GATEWAY_UNAUTHORIZED'))->toBe(PaymentErrorCode::InvalidCredentials)
        ->and(ZainCashErrorMap::toCode('PAYMENT_GATEWAY_TRANSACTION_NOT_FOUND'))->toBe(PaymentErrorCode::Unknown);
});

it('maps common error substrings', function () {
    expect(ZainCashErrorMap::toCode('Insufficient Balance'))->toBe(PaymentErrorCode::InsufficientFunds)
        ->and(ZainCashErrorMap::toCode('cancelled by user'))->toBe(PaymentErrorCode::UserCancelled)
        ->and(ZainCashErrorMap::toCode('transaction expired'))->toBe(PaymentErrorCode::Expired)
        ->and(ZainCashErrorMap::toCode('request timeout'))->toBe(PaymentErrorCode::Timeout)
        ->and(ZainCashErrorMap::toCode('something else'))->toBe(PaymentErrorCode::Unknown);
});
