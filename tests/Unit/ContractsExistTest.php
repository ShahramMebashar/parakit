<?php
declare(strict_types=1);

use Gutian\Parakit\Contracts\PaymentGateway;
use Gutian\Parakit\Contracts\SupportsRefund;
use Gutian\Parakit\Contracts\SupportsTokenization;
use Gutian\Parakit\Contracts\SupportsStatusCheck;
use Gutian\Parakit\Exceptions\PaymentException;
use Gutian\Parakit\Exceptions\GatewayUnavailableException;
use Gutian\Parakit\Exceptions\InvalidWebhookSignatureException;
use Gutian\Parakit\Exceptions\DuplicateWebhookException;
use Gutian\Parakit\Exceptions\UnsupportedGatewayException;
use Gutian\Parakit\Exceptions\IllegalStateTransitionException;

it('declares the four capability contracts', function () {
    expect(interface_exists(PaymentGateway::class))->toBeTrue()
        ->and(interface_exists(SupportsRefund::class))->toBeTrue()
        ->and(interface_exists(SupportsTokenization::class))->toBeTrue()
        ->and(interface_exists(SupportsStatusCheck::class))->toBeTrue();
});

it('exposes the documented exception hierarchy', function () {
    expect(is_subclass_of(GatewayUnavailableException::class, PaymentException::class))->toBeTrue()
        ->and(is_subclass_of(InvalidWebhookSignatureException::class, PaymentException::class))->toBeTrue()
        ->and(is_subclass_of(DuplicateWebhookException::class, PaymentException::class))->toBeTrue()
        ->and(is_subclass_of(UnsupportedGatewayException::class, PaymentException::class))->toBeTrue()
        ->and(is_subclass_of(IllegalStateTransitionException::class, PaymentException::class))->toBeTrue();
});
