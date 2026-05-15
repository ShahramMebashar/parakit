<?php
declare(strict_types=1);

use Gutian\Parakit\Models\PaymentTransaction;
use Gutian\Parakit\Models\PaymentWebhookEvent;
use Gutian\Parakit\Enums\PaymentStatus;
use Gutian\Parakit\Enums\Currency;
use Gutian\Parakit\Contracts\PaymentGateway;
use Gutian\Parakit\DTOs\WebhookPayload;
use Illuminate\Support\Facades\Event;
use Gutian\Parakit\Events\PaymentSucceeded;
use Gutian\Parakit\Events\WebhookReceived;

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('parakit.gateways.stub', ['driver' => 'stub']);
    config()->set('parakit.webhooks.tolerance_seconds', 300);
});

function registerStubDriver(string $eventId, PaymentStatus $status, DateTimeImmutable $occurredAt): void
{
    app('parakit.manager')->flushResolved();
    app('parakit.manager')->extend('stub', function () use ($eventId, $status, $occurredAt) {
        return new class($eventId, $status, $occurredAt) implements PaymentGateway {
            public function __construct(
                private string $eventId,
                private PaymentStatus $status,
                private DateTimeImmutable $occurredAt,
            ) {}
            public function charge($r): \Gutian\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
            public function handleWebhook(\Illuminate\Http\Request $r): WebhookPayload {
                return new WebhookPayload(
                    gateway: 'stub', gatewayTransactionId: 'gw_1', reference: 'ord_1',
                    status: $this->status, amount: 5000, currency: Currency::IQD,
                    eventId: $this->eventId, occurredAt: $this->occurredAt,
                );
            }
            public function name(): string { return 'stub'; }
        };
    });
}

it('updates the transaction status via the state machine and fires PaymentSucceeded', function () {
    Event::fake([PaymentSucceeded::class, WebhookReceived::class]);

    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    registerStubDriver('evt_1', PaymentStatus::Paid, new DateTimeImmutable());

    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
    Event::assertDispatched(PaymentSucceeded::class);
    expect(PaymentWebhookEvent::count())->toBe(1);
    expect(PaymentWebhookEvent::first()->processed_at)->not->toBeNull();
});

it('treats a duplicate event_id as 200 OK without re-applying', function () {
    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    registerStubDriver('evt_dup', PaymentStatus::Paid, new DateTimeImmutable());

    $this->postJson('/payments/webhooks/stub')->assertStatus(200);
    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentWebhookEvent::count())->toBe(1);
});

it('rejects webhooks older than tolerance_seconds with 400', function () {
    registerStubDriver('evt_old', PaymentStatus::Paid, new DateTimeImmutable('-1 hour'));
    $this->postJson('/payments/webhooks/stub')->assertStatus(400);
});

it('preserves a webhook arriving before the local tx commits (processed_at left null for later replay)', function () {
    // No PaymentTransaction created on purpose — simulates webhook arriving
    // before our charge() committed the local row.
    registerStubDriver('evt_race', PaymentStatus::Paid, new DateTimeImmutable());

    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentWebhookEvent::count())->toBe(1);
    expect(PaymentWebhookEvent::first()->processed_at)->toBeNull();
});

it('skips illegal transitions silently (logs, returns 200) without re-firing events', function () {
    Event::fake([PaymentSucceeded::class]);

    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Refunded, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    registerStubDriver('evt_2', PaymentStatus::Paid, new DateTimeImmutable());
    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Refunded);
    Event::assertNotDispatched(PaymentSucceeded::class);
});
