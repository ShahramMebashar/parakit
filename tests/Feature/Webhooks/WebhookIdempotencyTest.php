<?php
declare(strict_types=1);

use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Models\PaymentWebhookEvent;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\DTOs\WebhookPayload;
use Illuminate\Support\Facades\Event;
use Froshly\Parakit\Events\PaymentSucceeded;
use Froshly\Parakit\Events\WebhookReceived;
use Froshly\Parakit\Support\WebhookProcessor;

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
            public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
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

it('self-heals on a gateway retry when an orphan event becomes applyable', function () {
    Event::fake([PaymentSucceeded::class]);

    // First delivery: no local transaction yet, so the event row is parked
    // with processed_at = null.
    registerStubDriver('evt_orphan', PaymentStatus::Paid, new DateTimeImmutable());
    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentWebhookEvent::first()->processed_at)->toBeNull();

    // Now the charge() side commits the local row (race resolved).
    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_1', 'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    // Gateway retries the same event. Used to return 200 duplicate cold
    // without applying; now it heals — the parked event lands on the tx.
    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid)
        ->and(PaymentWebhookEvent::first()->processed_at)->not->toBeNull();
    Event::assertDispatched(PaymentSucceeded::class);
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

it('sets refunded_amount to the full transaction amount on a Refunded webhook', function () {
    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Paid, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    registerStubDriver('evt_refund_full', PaymentStatus::Refunded, new DateTimeImmutable());
    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    $tx = PaymentTransaction::first();
    expect($tx->status)->toBe(PaymentStatus::Refunded)
        ->and((int) $tx->refunded_amount)->toBe(5000);
});

it('accumulates refunded_amount across partial refund webhooks', function () {
    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_partial', 'gateway_transaction_id' => 'gw_p',
        'status' => PaymentStatus::Paid, 'amount' => 10_000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    // First partial: gateway reports 3000 refunded.
    app('parakit.manager')->flushResolved();
    app('parakit.manager')->extend('stub', function () {
        return new class implements PaymentGateway {
            public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
            public function handleWebhook(\Illuminate\Http\Request $r): WebhookPayload {
                return new WebhookPayload(
                    gateway: 'stub', gatewayTransactionId: 'gw_p', reference: 'ord_partial',
                    status: PaymentStatus::PartiallyRefunded, amount: 3000, currency: Currency::IQD,
                    eventId: 'evt_partial_1', occurredAt: new DateTimeImmutable(),
                );
            }
            public function name(): string { return 'stub'; }
        };
    });
    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect((int) PaymentTransaction::first()->refunded_amount)->toBe(3000);

    // Second partial: another 2000.
    app('parakit.manager')->flushResolved();
    app('parakit.manager')->extend('stub', function () {
        return new class implements PaymentGateway {
            public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
            public function handleWebhook(\Illuminate\Http\Request $r): WebhookPayload {
                return new WebhookPayload(
                    gateway: 'stub', gatewayTransactionId: 'gw_p', reference: 'ord_partial',
                    status: PaymentStatus::PartiallyRefunded, amount: 2000, currency: Currency::IQD,
                    eventId: 'evt_partial_2', occurredAt: new DateTimeImmutable(),
                );
            }
            public function name(): string { return 'stub'; }
        };
    });
    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect((int) PaymentTransaction::first()->refunded_amount)->toBe(5000);
});

it('does not re-apply an already processed partial refund event', function () {
    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_partial_once', 'gateway_transaction_id' => 'gw_once',
        'status' => PaymentStatus::Paid, 'amount' => 10_000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    $payload = new WebhookPayload(
        gateway: 'stub',
        gatewayTransactionId: 'gw_once',
        reference: 'ord_partial_once',
        status: PaymentStatus::PartiallyRefunded,
        amount: 3000,
        currency: Currency::IQD,
        eventId: 'evt_partial_once',
        occurredAt: new DateTimeImmutable(),
    );

    $event = PaymentWebhookEvent::create([
        'gateway' => 'stub',
        'event_id' => 'evt_partial_once',
        'status' => PaymentStatus::PartiallyRefunded->value,
        'payload' => [],
    ]);

    $processor = app(WebhookProcessor::class);
    $processor->applyToTransaction($payload, $event);
    $processor->applyToTransaction($payload, $event);

    expect((int) PaymentTransaction::first()->refunded_amount)->toBe(3000)
        ->and(PaymentWebhookEvent::first()->processed_at)->not->toBeNull();
});

it('rolls back the event row when applyToTransaction fails mid-flight (no orphaned dedupe rows)', function () {
    // Simulate a listener crash: a PaymentSucceeded listener throws.
    // The event row insert and the tx update must both roll back so the
    // gateway's retry is processed afresh rather than dedupe-200'd.
    \Illuminate\Support\Facades\Event::listen(PaymentSucceeded::class, function () {
        throw new RuntimeException('listener exploded');
    });

    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    registerStubDriver('evt_atomic', PaymentStatus::Paid, new DateTimeImmutable());
    $this->postJson('/payments/webhooks/stub')->assertStatus(500);

    expect(PaymentWebhookEvent::count())->toBe(0)
        ->and(PaymentTransaction::first()->status)->toBe(PaymentStatus::Pending);
});
