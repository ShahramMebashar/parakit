<?php
declare(strict_types=1);

use Shah\Parakit\Enums\PaymentStatus;

it('classifies terminal states', function () {
    expect(PaymentStatus::Paid->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Failed->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Refunded->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Expired->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Pending->isTerminal())->toBeFalse()
        ->and(PaymentStatus::Processing->isTerminal())->toBeFalse();
});

it('classifies success only for Paid and Refunded variants', function () {
    expect(PaymentStatus::Paid->isSuccessful())->toBeTrue()
        ->and(PaymentStatus::PartiallyRefunded->isSuccessful())->toBeTrue()
        ->and(PaymentStatus::Refunded->isSuccessful())->toBeTrue()
        ->and(PaymentStatus::Failed->isSuccessful())->toBeFalse();
});

it('allows legal forward transitions', function () {
    expect(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Processing))->toBeTrue()
        ->and(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Paid))->toBeTrue()
        ->and(PaymentStatus::Processing->canTransitionTo(PaymentStatus::Paid))->toBeTrue()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Refunded))->toBeTrue()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::PartiallyRefunded))->toBeTrue();
});

it('rejects illegal transitions', function () {
    expect(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Pending))->toBeFalse()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Failed))->toBeFalse()
        ->and(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid))->toBeFalse()
        ->and(PaymentStatus::Refunded->canTransitionTo(PaymentStatus::Paid))->toBeFalse();
});

it('treats same-status as a no-op transition (idempotent)', function () {
    expect(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Paid))->toBeTrue();
});
