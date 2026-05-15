<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Events\PaymentInitiated;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Support\CircuitBreaker;
use Froshly\Parakit\Support\CorrelationId;
use Froshly\Parakit\Support\IdempotencyKey;

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

        // Write-ahead: persist the charge intent BEFORE the gateway call, so a
        // failed/timed-out/crashed attempt still leaves an audit row and any
        // webhook that races the gateway response lands on an existing row.
        $tx = $this->persistIntent($request, $key);
        if ($tx === null) {
            // A transaction with this idempotency key already exists (the
            // cache entry expired but the row survives). Return its state
            // rather than charging again.
            $existing = PaymentTransaction::query()
                ->where('gateway', $this->gatewayName)
                ->where('idempotency_key', $key)
                ->firstOrFail();
            return $this->responseFromTransaction($existing);
        }

        // Fire exactly once per charge() call (not per retry attempt, and
        // not on idempotency-cache hits).
        event(new PaymentInitiated($this->gatewayName, $request, $tx));

        $maxAttempts = max(1, (int) config('parakit.reliability.retry.max_attempts', 1));
        $baseDelay = (int) config('parakit.reliability.retry.base_delay_ms', 200);

        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $response = $this->performCharge($request);
                $this->breaker->recordSuccess();
                $this->updateTransactionFromResponse($tx, $response);
                Cache::put($cacheKey, $response, $ttl);
                return $response;
            } catch (GatewayUnavailableException $e) {
                $this->breaker->recordFailure();
                if ($attempt >= $maxAttempts) {
                    $this->markFailed($tx);
                    throw $e;
                }
                $expBackoffMs = $baseDelay * (2 ** ($attempt - 1));
                $jitterMs = random_int(0, max(1, $baseDelay));
                usleep(($expBackoffMs + $jitterMs) * 1000);
            } catch (\Throwable $e) {
                $this->breaker->recordFailure();
                $this->markFailed($tx);
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

    /**
     * Persist the charge intent as a Pending PaymentTransaction. Returns null
     * when a row with this idempotency key already exists — the caller then
     * returns that existing row instead of charging again.
     */
    private function persistIntent(PaymentRequest $request, string $key): ?PaymentTransaction
    {
        try {
            return PaymentTransaction::create([
                'gateway' => $this->gatewayName,
                'reference' => $request->reference,
                'status' => PaymentStatus::Pending,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'idempotency_key' => $key,
                'correlation_id' => $this->correlationId(),
                'metadata' => $request->metadata,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE class 23 = integrity constraint violation (23000
            // MySQL/SQLite, 23505 Postgres) — the unique idempotency_key
            // index rejected a duplicate. Anything else is a real DB error.
            if (!str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }
            return null;
        }
    }

    private function updateTransactionFromResponse(PaymentTransaction $tx, PaymentResponse $response): void
    {
        $tx->gateway_transaction_id = $response->gatewayTransactionId;
        $tx->last_raw_response = $response->raw;
        if ($response->expiresAt !== null) {
            $tx->expires_at = \Illuminate\Support\Carbon::instance($response->expiresAt);
        }

        // Pending->Pending (the normal FIB/ZainCash charge case) is a no-op
        // for transitionTo(), so save the other columns explicitly.
        if ($response->status !== $tx->status) {
            $tx->transitionTo($response->status);
        } else {
            $tx->save();
        }
    }

    private function markFailed(PaymentTransaction $tx): void
    {
        // Best-effort: a DB error (or illegal transition) while recording the
        // failure must never mask the original gateway exception the caller
        // needs to see.
        try {
            $tx->transitionTo(PaymentStatus::Failed);
        } catch (\Throwable) {
            // intentionally swallowed
        }
    }

    private function responseFromTransaction(PaymentTransaction $tx): PaymentResponse
    {
        return new PaymentResponse(
            success: $tx->status !== PaymentStatus::Failed,
            gateway: $tx->gateway,
            gatewayTransactionId: $tx->gateway_transaction_id,
            reference: $tx->reference,
            status: $tx->status,
            amount: (int) $tx->amount,
            currency: $tx->currency,
            correlationId: $tx->correlation_id,
            raw: $tx->last_raw_response ?? [],
        );
    }
}
