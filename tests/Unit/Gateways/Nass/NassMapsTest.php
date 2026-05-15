<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\Nass\NassCurrencyMap;
use Froshly\Parakit\Gateways\Nass\NassStatusMap;
use Froshly\Parakit\Gateways\Nass\NassErrorMap;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\PaymentErrorCode;

it('maps Currency enum to NassPay ISO numeric codes', function () {
    expect(NassCurrencyMap::toCode(Currency::IQD))->toBe('368')
        ->and(NassCurrencyMap::toCode(Currency::USD))->toBe('840');
});

it('maps NassPay ISO numeric codes back to Currency', function () {
    expect(NassCurrencyMap::fromCode('368'))->toBe(Currency::IQD)
        ->and(NassCurrencyMap::fromCode('840'))->toBe(Currency::USD);
});

it('returns null for an unknown numeric currency code', function () {
    expect(NassCurrencyMap::fromCode('999'))->toBeNull();
});

it('maps responseCode 00 to Paid', function () {
    expect(NassStatusMap::toStatus('00'))->toBe(PaymentStatus::Paid);
});

it('maps responseCode -25 to Cancelled', function () {
    expect(NassStatusMap::toStatus('-25'))->toBe(PaymentStatus::Cancelled);
});

it('maps in-progress responseCodes to Pending', function () {
    expect(NassStatusMap::toStatus('-33'))->toBe(PaymentStatus::Pending)
        ->and(NassStatusMap::toStatus('-39'))->toBe(PaymentStatus::Pending)
        ->and(NassStatusMap::toStatus('-40'))->toBe(PaymentStatus::Pending)
        ->and(NassStatusMap::toStatus('-47'))->toBe(PaymentStatus::Pending);
});

it('maps other negative responseCodes to Failed', function () {
    expect(NassStatusMap::toStatus('-8'))->toBe(PaymentStatus::Failed)
        ->and(NassStatusMap::toStatus('-30'))->toBe(PaymentStatus::Failed);
});

it('falls back to Pending for an unrecognised responseCode', function () {
    expect(NassStatusMap::toStatus(''))->toBe(PaymentStatus::Pending)
        ->and(NassStatusMap::toStatus('99'))->toBe(PaymentStatus::Pending);
});

it('maps known NassPay error codes to PaymentErrorCode', function () {
    expect(NassErrorMap::toCode('-10'))->toBe(PaymentErrorCode::InvalidAmount)
        ->and(NassErrorMap::toCode('-20'))->toBe(PaymentErrorCode::Timeout)
        ->and(NassErrorMap::toCode('-21'))->toBe(PaymentErrorCode::DuplicateTransaction)
        ->and(NassErrorMap::toCode('-25'))->toBe(PaymentErrorCode::UserCancelled);
});

it('falls back to Unknown for unmapped error codes', function () {
    expect(NassErrorMap::toCode('-8'))->toBe(PaymentErrorCode::Unknown)
        ->and(NassErrorMap::toCode('00'))->toBe(PaymentErrorCode::Unknown);
});
