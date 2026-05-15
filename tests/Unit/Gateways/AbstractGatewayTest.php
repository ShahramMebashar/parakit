<?php
declare(strict_types=1);

use Gutian\Parakit\Gateways\AbstractGateway;
use Gutian\Parakit\DTOs\PaymentRequest;
use Gutian\Parakit\DTOs\PaymentResponse;
use Gutian\Parakit\DTOs\WebhookPayload;
use Gutian\Parakit\Enums\Currency;
use Gutian\Parakit\Enums\PaymentStatus;
use Gutian\Parakit\Events\PaymentInitiated;
use Gutian\Parakit\Exceptions\GatewayUnavailableException;
use Gutian\Parakit\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
});

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

    $gw = new class('dummy', []) extends \Gutian\Parakit\Gateways\AbstractGateway {
        public int $calls = 0;
        protected function performCharge(\Gutian\Parakit\DTOs\PaymentRequest $r): \Gutian\Parakit\DTOs\PaymentResponse {
            $this->calls++;
            if ($this->calls < 3) {
                throw new \Gutian\Parakit\Exceptions\GatewayUnavailableException('transient');
            }
            return new \Gutian\Parakit\DTOs\PaymentResponse(
                success: true, gateway: 'dummy', gatewayTransactionId: 'ok',
                reference: $r->reference, status: \Gutian\Parakit\Enums\PaymentStatus::Pending,
                amount: $r->amount, currency: $r->currency, correlationId: 'cid',
            );
        }
        public function handleWebhook(\Illuminate\Http\Request $r): \Gutian\Parakit\DTOs\WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };

    $resp = $gw->charge(new \Gutian\Parakit\DTOs\PaymentRequest('ord_retry', 1, \Gutian\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'k_retry'));
    expect($gw->calls)->toBe(3)->and($resp->success)->toBeTrue();
});

it('fires PaymentInitiated exactly once per charge call (not per retry, not on idempotent re-hits)', function () {
    config()->set('parakit.reliability.retry.max_attempts', 3);
    config()->set('parakit.reliability.retry.base_delay_ms', 1);

    Illuminate\Support\Facades\Event::fake([\Gutian\Parakit\Events\PaymentInitiated::class]);

    $gw = new class('dummy', []) extends \Gutian\Parakit\Gateways\AbstractGateway {
        public int $calls = 0;
        protected function performCharge(\Gutian\Parakit\DTOs\PaymentRequest $r): \Gutian\Parakit\DTOs\PaymentResponse {
            $this->calls++;
            if ($this->calls < 2) {
                throw new \Gutian\Parakit\Exceptions\GatewayUnavailableException('flap');
            }
            return new \Gutian\Parakit\DTOs\PaymentResponse(
                success: true, gateway: 'dummy', gatewayTransactionId: 'g',
                reference: $r->reference, status: \Gutian\Parakit\Enums\PaymentStatus::Pending,
                amount: $r->amount, currency: $r->currency, correlationId: 'c',
            );
        }
        public function handleWebhook(\Illuminate\Http\Request $r): \Gutian\Parakit\DTOs\WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };

    $req = new \Gutian\Parakit\DTOs\PaymentRequest('ord_init', 1, \Gutian\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'init1');
    $gw->charge($req);
    $gw->charge($req); // second call is an idempotent cache hit

    Illuminate\Support\Facades\Event::assertDispatchedTimes(\Gutian\Parakit\Events\PaymentInitiated::class, 1);
});

it('persists a Pending PaymentTransaction before the gateway call (write-ahead)', function () {
    $gw = new class('dummy', []) extends AbstractGateway {
        public ?PaymentTransaction $seenDuringCharge = null;
        protected function performCharge(PaymentRequest $r): PaymentResponse {
            // The row must already exist by the time the gateway is called.
            $this->seenDuringCharge = PaymentTransaction::query()
                ->where('reference', $r->reference)->first();
            return new PaymentResponse(
                success: true, gateway: 'dummy', gatewayTransactionId: 'g1',
                reference: $r->reference, status: PaymentStatus::Pending,
                amount: $r->amount, currency: $r->currency, correlationId: 'c',
            );
        }
        public function handleWebhook(Request $r): WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };

    $gw->charge(new PaymentRequest('ord_w1', 5000, Currency::IQD, 'd', idempotencyKey: 'w1'));

    expect($gw->seenDuringCharge)->not->toBeNull()
        ->and($gw->seenDuringCharge->status)->toBe(PaymentStatus::Pending)
        ->and($gw->seenDuringCharge->gateway)->toBe('dummy')
        ->and((int) $gw->seenDuringCharge->amount)->toBe(5000)
        ->and($gw->seenDuringCharge->idempotency_key)->toBe('w1');
});

it('updates the transaction with the gateway response on success', function () {
    $gw = new DummyGateway('dummy', []);
    $gw->charge(new PaymentRequest('ord_w2', 5000, Currency::IQD, 'd', idempotencyKey: 'w2'));

    $tx = PaymentTransaction::where('idempotency_key', 'w2')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->gateway_transaction_id)->toBe('g_1')
        ->and($tx->status)->toBe(PaymentStatus::Pending);
});

it('marks the transaction Failed when the gateway fails, and rethrows', function () {
    config()->set('parakit.reliability.retry.max_attempts', 1);

    $gw = new class('dummy', []) extends AbstractGateway {
        protected function performCharge(PaymentRequest $r): PaymentResponse {
            throw new GatewayUnavailableException('down');
        }
        public function handleWebhook(Request $r): WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };
    $req = new PaymentRequest('ord_w3', 5000, Currency::IQD, 'd', idempotencyKey: 'w3');

    expect(fn () => $gw->charge($req))->toThrow(GatewayUnavailableException::class);

    $tx = PaymentTransaction::where('idempotency_key', 'w3')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->status)->toBe(PaymentStatus::Failed);
});

it('marks the transaction Failed on a non-gateway exception and rethrows', function () {
    $gw = new class('dummy', []) extends AbstractGateway {
        protected function performCharge(PaymentRequest $r): PaymentResponse {
            throw new RuntimeException('bug');
        }
        public function handleWebhook(Request $r): WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };
    $req = new PaymentRequest('ord_w4', 5000, Currency::IQD, 'd', idempotencyKey: 'w4');

    expect(fn () => $gw->charge($req))->toThrow(RuntimeException::class, 'bug');

    expect(PaymentTransaction::where('idempotency_key', 'w4')->first()->status)
        ->toBe(PaymentStatus::Failed);
});

it('does not duplicate the row or re-charge when the idempotency key already exists', function () {
    $gw = new DummyGateway('dummy', []);
    $req = new PaymentRequest('ord_w5', 5000, Currency::IQD, 'd', idempotencyKey: 'w5');

    $gw->charge($req);
    Cache::flush(); // drop the response cache; the DB row survives
    $second = $gw->charge($req);

    expect($gw->calls)->toBe(1)
        ->and(PaymentTransaction::where('idempotency_key', 'w5')->count())->toBe(1)
        ->and($second->reference)->toBe('ord_w5');
});

it('fires PaymentInitiated carrying the persisted transaction', function () {
    $captured = null;
    Illuminate\Support\Facades\Event::listen(
        PaymentInitiated::class,
        function (PaymentInitiated $e) use (&$captured) { $captured = $e; },
    );

    $gw = new DummyGateway('dummy', []);
    $gw->charge(new PaymentRequest('ord_w6', 5000, Currency::IQD, 'd', idempotencyKey: 'w6'));

    expect($captured)->not->toBeNull()
        ->and($captured->transaction)->toBeInstanceOf(PaymentTransaction::class)
        ->and($captured->transaction->reference)->toBe('ord_w6');
});

it('opens the circuit after threshold failures and fails fast thereafter', function () {
    config()->set('parakit.reliability.retry.max_attempts', 1);
    config()->set('parakit.reliability.circuit_breaker.failure_threshold', 2);
    config()->set('parakit.reliability.circuit_breaker.cooldown_seconds', 60);

    $gw = new class('dummy', []) extends \Gutian\Parakit\Gateways\AbstractGateway {
        public int $calls = 0;
        protected function performCharge(\Gutian\Parakit\DTOs\PaymentRequest $r): \Gutian\Parakit\DTOs\PaymentResponse {
            $this->calls++;
            throw new \Gutian\Parakit\Exceptions\GatewayUnavailableException('boom');
        }
        public function handleWebhook(\Illuminate\Http\Request $r): \Gutian\Parakit\DTOs\WebhookPayload { throw new RuntimeException('n/a'); }
        public function name(): string { return 'dummy'; }
    };

    $req1 = new \Gutian\Parakit\DTOs\PaymentRequest('ord_a', 1, \Gutian\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'a');
    $req2 = new \Gutian\Parakit\DTOs\PaymentRequest('ord_b', 1, \Gutian\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'b');
    $req3 = new \Gutian\Parakit\DTOs\PaymentRequest('ord_c', 1, \Gutian\Parakit\Enums\Currency::IQD, 'd', idempotencyKey: 'c');

    try { $gw->charge($req1); } catch (\Throwable) {}
    try { $gw->charge($req2); } catch (\Throwable) {}

    // Breaker should now be open: third call must NOT reach performCharge.
    $caught = false;
    try { $gw->charge($req3); } catch (\Gutian\Parakit\Exceptions\GatewayUnavailableException) { $caught = true; }

    expect($caught)->toBeTrue()->and($gw->calls)->toBe(2);
});
