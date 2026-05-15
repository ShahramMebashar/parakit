<?php
declare(strict_types=1);

use Shah\Parakit\DTOs\WebhookPayload;
use Shah\Parakit\DTOs\RefundRequest;
use Shah\Parakit\DTOs\RefundResponse;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Enums\PaymentStatus;

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

it('rejects refund of non-positive amount', function () {
    new RefundRequest(transactionId: 'gw_1', amount: 0);
})->throws(InvalidArgumentException::class);

it('builds a refund response', function () {
    $r = new RefundResponse(success: true, refundId: 'r_1', refundedAmount: 500);
    expect($r->success)->toBeTrue();
});
