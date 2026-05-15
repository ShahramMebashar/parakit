<?php
declare(strict_types=1);

use Shah\Parakit\Gateways\ZainCash\ZainCashErrorMap;
use Shah\Parakit\Enums\PaymentErrorCode;

it('maps known ZainCash error strings', function () {
    expect(ZainCashErrorMap::toCode('Insufficient Balance'))->toBe(PaymentErrorCode::InsufficientFunds)
        ->and(ZainCashErrorMap::toCode('cancelled by user'))->toBe(PaymentErrorCode::UserCancelled)
        ->and(ZainCashErrorMap::toCode('unknown thing'))->toBe(PaymentErrorCode::Unknown);
});
