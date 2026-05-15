<?php
declare(strict_types=1);

use Shah\Parakit\Enums\PaymentErrorCode;
use Shah\Parakit\Enums\Gateway;

it('exposes all error codes from the spec', function () {
    expect(PaymentErrorCode::cases())->toHaveCount(12);
    expect(PaymentErrorCode::InsufficientFunds->value)->toBe('insufficient_funds');
    expect(PaymentErrorCode::SignatureInvalid->value)->toBe('signature_invalid');
});

it('exposes v0.1 gateway identifiers', function () {
    expect(Gateway::Fib->value)->toBe('fib')
        ->and(Gateway::ZainCash->value)->toBe('zaincash');
});
