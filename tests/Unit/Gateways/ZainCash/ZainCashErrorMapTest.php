<?php
declare(strict_types=1);

use Gutian\Parakit\Gateways\ZainCash\ZainCashErrorMap;
use Gutian\Parakit\Enums\PaymentErrorCode;

it('maps known ZainCash error strings', function () {
    expect(ZainCashErrorMap::toCode('Insufficient Balance'))->toBe(PaymentErrorCode::InsufficientFunds)
        ->and(ZainCashErrorMap::toCode('cancelled by user'))->toBe(PaymentErrorCode::UserCancelled)
        ->and(ZainCashErrorMap::toCode('unknown thing'))->toBe(PaymentErrorCode::Unknown);
});
