<?php
declare(strict_types=1);

use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('parakit.gateways.stub', ['driver' => 'stub']);

    app('parakit.manager')->extend('stub', function () {
        return new class implements PaymentGateway {
            public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
            public function handleWebhook(\Illuminate\Http\Request $r): WebhookPayload {
                return new WebhookPayload(
                    gateway: 'stub',
                    gatewayTransactionId: 'g_1',
                    reference: 'ord_1',
                    status: PaymentStatus::Paid,
                    amount: 5000,
                    currency: Currency::IQD,
                    eventId: 'evt_' . $r->input('eid', '1'),
                    occurredAt: new DateTimeImmutable(),
                );
            }
            public function name(): string { return 'stub'; }
        };
    });
});

it('routes POST /payments/webhooks/{gateway} to the matching driver', function () {
    $resp = $this->postJson('/payments/webhooks/stub', ['eid' => '1']);
    $resp->assertStatus(200);
});

it('returns 404 for unknown gateway', function () {
    $this->postJson('/payments/webhooks/nonsense')->assertStatus(404);
});

it('strips sensitive headers from WebhookVerificationFailed events', function () {
    app('parakit.manager')->flushResolved();
    config()->set('parakit.gateways.sigbad', ['driver' => 'sigbad']);
    app('parakit.manager')->extend('sigbad', fn () => new class implements \Froshly\Parakit\Contracts\PaymentGateway {
        public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
        public function handleWebhook(\Illuminate\Http\Request $r): \Froshly\Parakit\DTOs\WebhookPayload {
            throw new \Froshly\Parakit\Exceptions\InvalidWebhookSignatureException('bad');
        }
        public function name(): string { return 'sigbad'; }
    });

    \Illuminate\Support\Facades\Event::fake([\Froshly\Parakit\Events\WebhookVerificationFailed::class]);

    $this->postJson('/payments/webhooks/sigbad', [], [
        'Authorization' => 'Bearer SUPER-SECRET',
        'Cookie' => 'session=abc',
        'X-Signature' => 'kept',
    ])->assertStatus(401);

    \Illuminate\Support\Facades\Event::assertDispatched(
        \Froshly\Parakit\Events\WebhookVerificationFailed::class,
        function ($e) {
            $flat = json_encode($e->headers);
            return !str_contains($flat, 'SUPER-SECRET')
                && !str_contains(strtolower($flat), 'session=abc')
                && str_contains(strtolower($flat), 'x-signature');
        },
    );
});

it('returns 500 (driver bug) when handleWebhook throws an unexpected exception', function () {
    app('parakit.manager')->flushResolved();
    config()->set('parakit.gateways.buggy', ['driver' => 'buggy']);
    app('parakit.manager')->extend('buggy', fn () => new class implements \Froshly\Parakit\Contracts\PaymentGateway {
        public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('n/a'); }
        public function handleWebhook(\Illuminate\Http\Request $r): \Froshly\Parakit\DTOs\WebhookPayload {
            throw new \LogicException('driver bug');
        }
        public function name(): string { return 'buggy'; }
    });

    $this->postJson('/payments/webhooks/buggy')->assertStatus(500);
});
