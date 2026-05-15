<?php
declare(strict_types=1);

namespace Shah\Parakit\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Shah\Parakit\Contracts\PaymentGateway;
use Shah\Parakit\DTOs\PaymentRequest;
use Shah\Parakit\DTOs\PaymentResponse;
use Shah\Parakit\DTOs\WebhookPayload;
use Shah\Parakit\Events\PaymentInitiated;
use Shah\Parakit\Exceptions\GatewayUnavailableException;
use Shah\Parakit\Support\CircuitBreaker;
use Shah\Parakit\Support\CorrelationId;
use Shah\Parakit\Support\IdempotencyKey;

abstract class AbstractGateway implements PaymentGateway
{
    protected CircuitBreaker $breaker;

    public function __construct(
        protected readonly string $gatewayName,
        protected readonly array $config,
    ) {
        $cb = (array) config('parakit.reliability.circuit_breaker');
        $this->breaker = new CircuitBreaker(
            $gatewayName,
            (int) ($cb['failure_threshold'] ?? 5),
            (int) ($cb['cooldown_seconds'] ?? 30),
        );
    }

    public function charge(PaymentRequest $request): PaymentResponse
    {
        if ($this->breaker->isOpen()) {
            throw new GatewayUnavailableException("Circuit open for {$this->gatewayName}");
        }

        $key = $request->idempotencyKey ?? IdempotencyKey::derive(
            $this->gatewayName,
            $request->reference,
            $request->amount,
            $request->currency->value,
        );
        $cacheKey = "parakit:idem:{$this->gatewayName}:{$key}";
        $ttl = (int) config('parakit.reliability.idempotency_ttl', 86400);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof PaymentResponse) {
            return $cached;
        }

        // Fire exactly once per charge() call (not per retry attempt, and
        // not on idempotency-cache hits).
        event(new PaymentInitiated($this->gatewayName, $request));

        $maxAttempts = max(1, (int) config('parakit.reliability.retry.max_attempts', 1));
        $baseDelay = (int) config('parakit.reliability.retry.base_delay_ms', 200);

        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->performCharge($request);
                $this->breaker->recordSuccess();
                Cache::put($cacheKey, $response, $ttl);
                return $response;
            } catch (GatewayUnavailableException $e) {
                $this->breaker->recordFailure();
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $expBackoffMs = $baseDelay * (2 ** ($attempt - 1));
                $jitterMs = random_int(0, max(1, $baseDelay));
                usleep(($expBackoffMs + $jitterMs) * 1000);
            } catch (\Throwable $e) {
                $this->breaker->recordFailure();
                throw $e;
            }
        }

        // Unreachable: the loop only exits via return or throw above.
        throw new GatewayUnavailableException("retry loop exhausted for {$this->gatewayName}");
    }

    abstract protected function performCharge(PaymentRequest $request): PaymentResponse;

    abstract public function handleWebhook(Request $request): WebhookPayload;

    public function name(): string
    {
        return $this->gatewayName;
    }

    protected function correlationId(): string
    {
        return CorrelationId::current();
    }
}
