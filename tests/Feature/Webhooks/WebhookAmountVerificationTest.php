<?php
declare(strict_types=1);

use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Events\PaymentSucceeded;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('parakit.gateways.stub', ['driver' => 'stub']);
    config()->set('parakit.webhooks.tolerance_seconds', 300);
});

/** Register a stub driver whose webhook reports a chosen status + amount. */
function registerAmountStub(int $webhookAmount, PaymentStatus $status = PaymentStatus::Paid): void
{
    app('parakit.manager')->flushResolved();
    app('parakit.manager')->extend('stub', function () use ($webhookAmount, $status) {
        return new class($webhookAmount, $status) implements PaymentGateway {
            public function __construct(private int $amount, private PaymentStatus $status) {}
            public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
            public function handleWebhook(\Illuminate\Http\Request $r): WebhookPayload {
                return new WebhookPayload(
                    gateway: 'stub', gatewayTransactionId: 'gw_1', reference: 'ord_1',
                    status: $this->status, amount: $this->amount, currency: Currency::IQD,
                    eventId: 'evt_' . $this->amount, occurredAt: new DateTimeImmutable(),
                );
            }
            public function name(): string { return 'stub'; }
        };
    });
}

function seedPendingStubTx(int $amount = 5000): void
{
    PaymentTransaction::create([
        'gateway' => 'stub', 'reference' => 'ord_1', 'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Pending, 'amount' => $amount,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);
}

it('applies the transition when the webhook amount matches the transaction', function () {
    seedPendingStubTx(5000);
    registerAmountStub(5000);

    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('does not flag a mismatch when the webhook reports amount 0 (not reported)', function () {
    seedPendingStubTx(5000);
    registerAmountStub(0);

    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('logs the mismatch but still applies the transition in the default (log) mode', function () {
    config()->set('parakit.webhooks.on_amount_mismatch', 'log');
    seedPendingStubTx(5000);
    registerAmountStub(9000);

    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('blocks the transition on an amount mismatch in reject mode', function () {
    Event::fake([PaymentSucceeded::class]);
    config()->set('parakit.webhooks.on_amount_mismatch', 'reject');
    seedPendingStubTx(5000);
    registerAmountStub(9000);

    $this->postJson('/payments/webhooks/stub')->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(PaymentSucceeded::class);
    // The event row is still marked processed — it was handled, just refused.
    expect(PaymentWebhookEvent::first()->processed_at)->not->toBeNull();
});
