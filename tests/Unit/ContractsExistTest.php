<?php
declare(strict_types=1);

use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\Contracts\SupportsTokenization;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\Exceptions\PaymentException;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Exceptions\DuplicateWebhookException;
use Froshly\Parakit\Exceptions\UnsupportedGatewayException;
use Froshly\Parakit\Exceptions\IllegalStateTransitionException;

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
