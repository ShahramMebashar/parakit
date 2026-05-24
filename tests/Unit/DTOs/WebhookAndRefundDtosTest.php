<?php
declare(strict_types=1);

use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\DTOs\RefundResponse;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;

it('builds a webhook payload', function () {
    $p = new WebhookPayload(
        gateway: 'fib',
        gatewayTransactionId: 'gw_1',
        reference: 'ord_1',
        status: PaymentStatus::Paid,
        amount: 5000,
        currency: Currency::IQD,
        eventId: 'evt_1',
        occurredAt: new DateTimeImmutable('2026-05-14T10:00:00Z'),
    );
    expect($p->eventId)->toBe('evt_1');
});

it('defaults correlationId to null and copies it immutably via withCorrelationId', function () {
    $p = new WebhookPayload(
        gateway: 'fib',
        gatewayTransactionId: 'gw_1',
        reference: 'ord_1',
        status: PaymentStatus::Paid,
        amount: 5000,
        currency: Currency::IQD,
        eventId: 'evt_1',
        occurredAt: new DateTimeImmutable('2026-05-14T10:00:00Z'),
    );
    expect($p->correlationId)->toBeNull();

    $tagged = $p->withCorrelationId('corr_123');
    expect($tagged->correlationId)->toBe('corr_123')
        ->and($tagged->eventId)->toBe('evt_1')
        ->and($tagged->amount)->toBe(5000)
        ->and($p->correlationId)->toBeNull(); // original untouched
});

it('rejects refund of non-positive amount', function () {
    new RefundRequest(transactionId: 'gw_1', amount: 0);
})->throws(InvalidArgumentException::class);

it('builds a refund response', function () {
    $r = new RefundResponse(success: true, refundId: 'r_1', refundedAmount: 500);
    expect($r->success)->toBeTrue();
});
