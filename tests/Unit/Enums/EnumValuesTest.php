<?php
declare(strict_types=1);

use Froshly\Parakit\Enums\PaymentErrorCode;

it('exposes all error codes from the spec', function () {
    expect(PaymentErrorCode::cases())->toHaveCount(12);
    expect(PaymentErrorCode::InsufficientFunds->value)->toBe('insufficient_funds');
    expect(PaymentErrorCode::SignatureInvalid->value)->toBe('signature_invalid');
});
