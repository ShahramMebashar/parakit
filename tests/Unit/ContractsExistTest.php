<?php
declare(strict_types=1);

use Shah\Parakit\Contracts\PaymentGateway;
use Shah\Parakit\Contracts\SupportsRefund;
use Shah\Parakit\Contracts\SupportsTokenization;
use Shah\Parakit\Contracts\SupportsStatusCheck;
use Shah\Parakit\Exceptions\PaymentException;
use Shah\Parakit\Exceptions\GatewayUnavailableException;
use Shah\Parakit\Exceptions\InvalidWebhookSignatureException;
use Shah\Parakit\Exceptions\DuplicateWebhookException;
use Shah\Parakit\Exceptions\UnsupportedGatewayException;
use Shah\Parakit\Exceptions\IllegalStateTransitionException;

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
