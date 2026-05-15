<?php
declare(strict_types=1);

use Gutian\Parakit\DTOs\PaymentRequest;
use Gutian\Parakit\DTOs\PaymentResponse;
use Gutian\Parakit\DTOs\PaymentError;
use Gutian\Parakit\Enums\Currency;
use Gutian\Parakit\Enums\PaymentErrorCode;
use Gutian\Parakit\Enums\PaymentStatus;

it('builds a payment request with minimal args', function () {
    $r = new PaymentRequest(
        reference: 'ord_1',
        amount: 5000,
        currency: Currency::IQD,
        description: 'Order 1',
    );
    expect($r->amount)->toBe(5000)
        ->and($r->currency)->toBe(Currency::IQD)
        ->and($r->metadata)->toBe([]);
});

it('rejects non-positive amounts', function () {
    new PaymentRequest('r', 0, Currency::IQD, 'd');
})->throws(InvalidArgumentException::class);

it('builds a successful response and reports failed=false', function () {
    $r = new PaymentResponse(
        success: true,
        gateway: 'fib',
        gatewayTransactionId: 'gw_1',
        reference: 'ord_1',
        status: PaymentStatus::Pending,
        amount: 5000,
        currency: Currency::IQD,
        correlationId: '01H...ULID',
    );
    expect($r->failed())->toBeFalse();
});

it('builds a failed response carrying an error', function () {
    $err = new PaymentError(PaymentErrorCode::InvalidAmount, 'AMT_BAD', 'Amount invalid');
    $r = new PaymentResponse(
        success: false,
        gateway: 'fib',
        gatewayTransactionId: null,
        reference: 'ord_1',
        status: PaymentStatus::Failed,
        amount: 5000,
        currency: Currency::IQD,
        correlationId: '01H...ULID',
        error: $err,
    );
    expect($r->failed())->toBeTrue()
        ->and($r->error->code)->toBe(PaymentErrorCode::InvalidAmount);
});
