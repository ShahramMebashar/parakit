<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Froshly\Parakit\Events\WebhookEvent;
use Froshly\Parakit\Events\WebhookReceived;
use Froshly\Parakit\Events\WebhookVerificationFailed;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;

it('lets one listener on WebhookEvent catch both webhook events with a uniform gateway()', function () {
    $heard = [];
    Event::listen(WebhookEvent::class, function (WebhookEvent $e) use (&$heard) {
        $heard[] = $e->gateway();
    });

    event(new WebhookReceived(new WebhookPayload(
        gateway: 'fib', gatewayTransactionId: 'gw_1', reference: 'ord_1',
        status: PaymentStatus::Paid, amount: 5000, currency: Currency::IQD,
        eventId: 'evt_1', occurredAt: new DateTimeImmutable('2026-05-14T10:00:00Z'),
    )));
    event(new WebhookVerificationFailed(gateway: 'zaincash', reason: 'bad sig'));

    expect($heard)->toBe(['fib', 'zaincash']);
});
