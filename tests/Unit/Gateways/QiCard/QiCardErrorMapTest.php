<?php
declare(strict_types=1);

use Froshly\Parakit\Enums\PaymentErrorCode;
use Froshly\Parakit\Gateways\QiCard\QiCardErrorMap;

it('maps credential-related codes to InvalidCredentials', function () {
    expect(QiCardErrorMap::toCode(27))->toBe(PaymentErrorCode::InvalidCredentials)
        ->and(QiCardErrorMap::toCode(9))->toBe(PaymentErrorCode::InvalidCredentials);
});

it('maps duplicate-resource codes to DuplicateTransaction', function () {
    foreach ([1, 7, 10, 34] as $code) {
        expect(QiCardErrorMap::toCode($code))->toBe(PaymentErrorCode::DuplicateTransaction);
    }
});

it('maps internal/external/processing codes to GatewayUnavailable', function () {
    foreach ([14, 23, 24] as $code) {
        expect(QiCardErrorMap::toCode($code))->toBe(PaymentErrorCode::GatewayUnavailable);
    }
});

it('maps validation codes to InvalidAmount', function () {
    foreach ([19, 21, 28] as $code) {
        expect(QiCardErrorMap::toCode($code))->toBe(PaymentErrorCode::InvalidAmount);
    }
});

it('maps order-already-cancelled to UserCancelled', function () {
    expect(QiCardErrorMap::toCode(3))->toBe(PaymentErrorCode::UserCancelled);
});

it('falls through to Unknown for unmapped codes', function () {
    foreach ([2, 4, 5, 6, 12, 15, 17, 18, 20, 22, 26, 30, 31, 32, 33, 35, 999] as $code) {
        expect(QiCardErrorMap::toCode($code))->toBe(PaymentErrorCode::Unknown);
    }
});
