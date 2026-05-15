<?php
declare(strict_types=1);

use Shah\Parakit\Gateways\AbstractGateway;
use Shah\Parakit\DTOs\PaymentRequest;
use Shah\Parakit\DTOs\PaymentResponse;
use Shah\Parakit\DTOs\WebhookPayload;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

class DummyGateway extends AbstractGateway {
    public int $calls = 0;
    protected function performCharge(PaymentRequest $r): PaymentResponse {
        $this->calls++;
        return new PaymentResponse(
            success: true, gateway: 'dummy', gatewayTransactionId: 'g_' . $this->calls,
            reference: $r->reference, status: PaymentStatus::Pending,
            amount: $r->amount, currency: $r->currency,
            correlationId: $this->correlationId(),
        );
    }
    public function handleWebhook(Request $r): WebhookPayload { throw new RuntimeException('n/a'); }
    public function name(): string { return 'dummy'; }
}

it('returns a cached response for the same idempotency key', function () {
    $gw = new DummyGateway('dummy', []);
    $req = new PaymentRequest('ord_1', 5000, Currency::IQD, 'd', idempotencyKey: 'k1');
    $a = $gw->charge($req);
    $b = $gw->charge($req);
    expect($gw->calls)->toBe(1)
        ->and($a->gatewayTransactionId)->toBe($b->gatewayTransactionId);
});

it('derives a key when caller did not supply one', function () {
    $gw = new DummyGateway('dummy', []);
    $r = new PaymentRequest('ord_2', 5000, Currency::IQD, 'd');
    $gw->charge($r);
    $gw->charge($r);
    expect($gw->calls)->toBe(1);
});

it('retries on transient failure and ultimately returns success', function () {
    config()->set('parakit.reliability.retry.max_attempts', 3);
    config()->set('parakit.reliability.retry.base_delay_ms', 1);

    $gw = new class('dummy', []) extends \Shah\Parakit\Gateways\AbstractGateway {
        public int $calls = 0;
        protected function performCharge(\Shah\Parakit\DTOs\PaymentRequest $r): \Shah\Parakit\DTOs\PaymentResponse {
            $this->calls++;
            if ($this->calls < 3) {
                throw new \Shah\Parakit\Exceptions\GatewayUnavailableException('transient');
            }
            return new \Shah\Parakit\DTOs\PaymentResponse(
                success: true, gateway: 'dummy', gatewayTransactionId: 'ok',
                reference: $r->reference, status: \Shah\Parakit\Enums\PaymentStatus::Pending,
                amount: $r->amount, currency: $r->currency, correlationId: 'cid',
            );
        }
        public function handleWebhook(\Illuminate\Http\Request $r): \Shah\Parakit\DTOs\WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };

    $resp = $gw->charge(new \Shah\Parakit\DTOs\PaymentRequest('ord_retry', 1, \Shah\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'k_retry'));
    expect($gw->calls)->toBe(3)->and($resp->success)->toBeTrue();
});

it('fires PaymentInitiated exactly once per charge call (not per retry, not on idempotent re-hits)', function () {
    config()->set('parakit.reliability.retry.max_attempts', 3);
    config()->set('parakit.reliability.retry.base_delay_ms', 1);

    Illuminate\Support\Facades\Event::fake([\Shah\Parakit\Events\PaymentInitiated::class]);

    $gw = new class('dummy', []) extends \Shah\Parakit\Gateways\AbstractGateway {
        public int $calls = 0;
        protected function performCharge(\Shah\Parakit\DTOs\PaymentRequest $r): \Shah\Parakit\DTOs\PaymentResponse {
            $this->calls++;
            if ($this->calls < 2) {
                throw new \Shah\Parakit\Exceptions\GatewayUnavailableException('flap');
            }
            return new \Shah\Parakit\DTOs\PaymentResponse(
                success: true, gateway: 'dummy', gatewayTransactionId: 'g',
                reference: $r->reference, status: \Shah\Parakit\Enums\PaymentStatus::Pending,
                amount: $r->amount, currency: $r->currency, correlationId: 'c',
            );
        }
        public function handleWebhook(\Illuminate\Http\Request $r): \Shah\Parakit\DTOs\WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };

    $req = new \Shah\Parakit\DTOs\PaymentRequest('ord_init', 1, \Shah\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'init1');
    $gw->charge($req);
    $gw->charge($req); // second call is an idempotent cache hit

    Illuminate\Support\Facades\Event::assertDispatchedTimes(\Shah\Parakit\Events\PaymentInitiated::class, 1);
});

it('opens the circuit after threshold failures and fails fast thereafter', function () {
    config()->set('parakit.reliability.retry.max_attempts', 1);
    config()->set('parakit.reliability.circuit_breaker.failure_threshold', 2);
    config()->set('parakit.reliability.circuit_breaker.cooldown_seconds', 60);

    $gw = new class('dummy', []) extends \Shah\Parakit\Gateways\AbstractGateway {
        public int $calls = 0;
        protected function performCharge(\Shah\Parakit\DTOs\PaymentRequest $r): \Shah\Parakit\DTOs\PaymentResponse {
            $this->calls++;
            throw new \Shah\Parakit\Exceptions\GatewayUnavailableException('boom');
        }
        public function handleWebhook(\Illuminate\Http\Request $r): \Shah\Parakit\DTOs\WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };

    $req1 = new \Shah\Parakit\DTOs\PaymentRequest('ord_a', 1, \Shah\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'a');
    $req2 = new \Shah\Parakit\DTOs\PaymentRequest('ord_b', 1, \Shah\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'b');
    $req3 = new \Shah\Parakit\DTOs\PaymentRequest('ord_c', 1, \Shah\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'c');

    try { $gw->charge($req1); } catch (\Throwable) {}
    try { $gw->charge($req2); } catch (\Throwable) {}

    // Breaker should now be open: third call must NOT reach performCharge.
    $caught = false;
    try { $gw->charge($req3); } catch (\Shah\Parakit\Exceptions\GatewayUnavailableException) { $caught = true; }

    expect($caught)->toBeTrue()->and($gw->calls)->toBe(2);
});
