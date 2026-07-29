<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\DTOs\PaymentError;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\DTOs\RefundResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\PaymentErrorCode;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Events\GatewayTimeout;
use Froshly\Parakit\Events\PaymentInitiated;
use Froshly\Parakit\Exceptions\GatewayTimeoutException;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Models\PaymentRefund;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Support\CircuitBreaker;
use Froshly\Parakit\Support\CorrelationId;
use Froshly\Parakit\Support\IdempotencyKey;
use Froshly\Parakit\Support\PersistedPayload;
use Froshly\Parakit\Support\PaymentLogger;
use InvalidArgumentException;

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
        $startMs = (int) (hrtime(true) / 1_000_000);
        try {
            $response = $this->chargeInner($request);
            $this->logCharge($request, $response, null, $startMs);
            return $response;
        } catch (\Throwable $e) {
            $this->logCharge($request, null, $e, $startMs);
            throw $e;
        }
    }

    private function chargeInner(PaymentRequest $request): PaymentResponse
    {
        if ($this->breaker->isOpen()) {
            throw new GatewayUnavailableException("Circuit open for {$this->gatewayName}");
        }

        $key = IdempotencyKey::localForRequest($this->gatewayName, $request);
        $cacheKey = IdempotencyKey::cacheKey($this->gatewayName, 'charge', $key);
        $ttl = (int) config('parakit.reliability.idempotency_ttl', 86400);

        $cached = Cache::get($cacheKey);
        if ($cached instanceof PaymentResponse) {
            return $cached;
        }

        // Write-ahead so a crashed attempt still leaves an audit row and races with the webhook land on an existing row.
        $tx = $this->persistIntent($request, $key);
        if ($tx === null) {
            $existing = PaymentTransaction::query()
                ->where('gateway', $this->gatewayName)
                ->where('idempotency_key', $key)
                ->firstOrFail();
            return $this->responseFromTransaction($existing);
        }

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
                Cache::put($cacheKey, $this->responseForStorage($response), $ttl);
                return $response;
            } catch (GatewayUnavailableException $e) {
                if ($e instanceof GatewayTimeoutException) {
                    event(new GatewayTimeout($this->gatewayName, $e->endpoint, $e->durationMs));
                }
                $this->breaker->recordFailure();
                if (! $this->retryChargeOnTransientFailure() || $attempt >= $maxAttempts) {
                    // A timeout or 5xx can happen after the provider accepted the
                    // request. Keep the write-ahead row Pending so an unknown
                    // remote outcome is never represented as a terminal failure.
                    throw $e;
                }
                $expBackoffMs = $baseDelay * (2 ** ($attempt - 1));
                $jitterMs = random_int(0, max(1, $baseDelay));
                usleep(($expBackoffMs + $jitterMs) * 1000);
            } catch (\Throwable $e) {
                $this->markFailed($tx);
                throw $e;
            }
        }

        throw new GatewayUnavailableException("retry loop exhausted for {$this->gatewayName}");
    }

    /**
     * Keyed refunds claim the operation in the DB before touching the gateway; transient/unknown
     * failures leave the claim pending so a retry cannot issue a second money-movement call.
     *
     * @param callable(): RefundResponse $perform
     */
    protected function refundIdempotent(RefundRequest $request, callable $perform): RefundResponse
    {
        $key = $request->idempotencyKey;
        if ($key === null || $key === '') {
            return $perform();
        }

        $storedKey = IdempotencyKey::forGatewayOperation($this->gatewayName, 'refund', $key);
        $attempt = $this->claimRefundAttempt($request, $storedKey);

        if (! $attempt->wasRecentlyCreated) {
            $this->assertSameRefundOperation($attempt, $request);

            if ($attempt->status === 'succeeded') {
                return $this->refundResponseFromAttempt($attempt);
            }

            throw new GatewayUnavailableException(
                "Refund already pending or outcome unknown for {$this->gatewayName} idempotency key"
            );
        }

        try {
            $response = $perform();
        } catch (GatewayUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $attempt->delete();
            throw $e;
        }

        if (! $response->success) {
            $attempt->delete();
            return $response;
        }

        $attempt->forceFill([
            'status' => 'succeeded',
            'gateway_refund_id' => $response->refundId,
            'refunded_amount' => $response->refundedAmount,
            'response' => $this->refundResponseToArray($response),
            'completed_at' => now(),
        ])->save();

        return $response;
    }

    abstract protected function performCharge(PaymentRequest $request): PaymentResponse;

    abstract public function handleWebhook(Request $request): WebhookPayload;

    /**
     * Disable for providers whose create endpoint has no merchant-supplied
     * idempotency identifier and therefore cannot safely collapse retries.
     */
    protected function retryChargeOnTransientFailure(): bool
    {
        return true;
    }

    public function name(): string
    {
        return $this->gatewayName;
    }

    protected function correlationId(): string
    {
        return CorrelationId::current();
    }

    /** Returns null when a row with this idempotency key already exists. */
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
            // SQLSTATE class 23 = integrity constraint violation; anything else is a real DB error.
            if (!str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }
            return null;
        }
    }

    private function claimRefundAttempt(RefundRequest $request, string $storedKey): PaymentRefund
    {
        try {
            return PaymentRefund::create([
                'gateway' => $this->gatewayName,
                'idempotency_key' => $storedKey,
                'gateway_transaction_id' => $request->transactionId,
                'amount' => $request->amount,
                'status' => 'pending',
            ]);
        } catch (QueryException $e) {
            if (!str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            /** @var PaymentRefund $attempt */
            $attempt = PaymentRefund::query()
                ->where('gateway', $this->gatewayName)
                ->where('idempotency_key', $storedKey)
                ->firstOrFail();

            return $attempt;
        }
    }

    private function assertSameRefundOperation(PaymentRefund $attempt, RefundRequest $request): void
    {
        if ($attempt->gateway_transaction_id === $request->transactionId && (int) $attempt->amount === $request->amount) {
            return;
        }

        throw new InvalidArgumentException(
            'Refund idempotency key was reused for a different transaction or amount'
        );
    }

    private function refundResponseFromAttempt(PaymentRefund $attempt): RefundResponse
    {
        $stored = is_array($attempt->response) ? $attempt->response : [];
        $raw = is_array($stored['raw'] ?? null) ? $stored['raw'] : [];
        $error = null;
        $storedError = $stored['error'] ?? null;

        if (is_array($storedError)) {
            $error = new PaymentError(
                code: PaymentErrorCode::tryFrom((string) ($storedError['code'] ?? '')) ?? PaymentErrorCode::Unknown,
                rawCode: (string) ($storedError['raw_code'] ?? ''),
                rawMessage: (string) ($storedError['raw_message'] ?? ''),
            );
        }

        return new RefundResponse(
            success: true,
            refundId: is_string($attempt->gateway_refund_id) && $attempt->gateway_refund_id !== '' ? $attempt->gateway_refund_id : null,
            refundedAmount: (int) $attempt->refunded_amount,
            error: $error,
            raw: $raw,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function refundResponseToArray(RefundResponse $response): array
    {
        return [
            'success' => $response->success,
            'refund_id' => $response->refundId,
            'refunded_amount' => $response->refundedAmount,
            'error' => $response->error === null ? null : [
                'code' => $response->error->code->value,
                'raw_code' => $response->error->rawCode,
                'raw_message' => $response->error->rawMessage,
            ],
            'raw' => PersistedPayload::prepare($response->raw),
        ];
    }

    private function updateTransactionFromResponse(PaymentTransaction $tx, PaymentResponse $response): void
    {
        $tx->gateway_transaction_id = $response->gatewayTransactionId;
        $tx->last_raw_response = PersistedPayload::prepare($response->raw);
        if ($response->expiresAt !== null) {
            $tx->expires_at = \Illuminate\Support\Carbon::instance($response->expiresAt);
        }

        // Pending->Pending is a no-op for transitionTo(), so save other columns explicitly.
        if ($response->status !== $tx->status) {
            $tx->transitionTo($response->status);
        } else {
            $tx->save();
        }
    }

    private function responseForStorage(PaymentResponse $response): PaymentResponse
    {
        return new PaymentResponse(
            success: $response->success,
            gateway: $response->gateway,
            gatewayTransactionId: $response->gatewayTransactionId,
            reference: $response->reference,
            status: $response->status,
            amount: $response->amount,
            currency: $response->currency,
            correlationId: $response->correlationId,
            redirectUrl: $response->redirectUrl,
            qrCode: $response->qrCode,
            deepLink: $response->deepLink,
            readableCode: $response->readableCode,
            expiresAt: $response->expiresAt,
            error: $response->error,
            raw: PersistedPayload::prepare($response->raw),
        );
    }

    private function markFailed(PaymentTransaction $tx): void
    {
        // Never mask the original gateway exception with a bookkeeping error.
        try {
            $tx->transitionTo(PaymentStatus::Failed);
        } catch (\Throwable) {
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

    private function logCharge(
        PaymentRequest $request,
        ?PaymentResponse $response,
        ?\Throwable $error,
        int $startMs,
    ): void {
        try {
            app(PaymentLogger::class)->record(
                action: 'charge',
                gateway: $this->gatewayName,
                endpoint: null,
                statusCode: null,
                durationMs: (int) (hrtime(true) / 1_000_000) - $startMs,
                request: $this->requestToArray($request),
                response: $response !== null ? $this->responseToArray($response) : [],
                correlationId: $this->correlationId(),
                errorMessage: $error?->getMessage(),
            );
        } catch (\Throwable) {
            // Audit logging must never mask the real charge outcome.
        }
    }

    /** @return array<string, mixed> */
    private function requestToArray(PaymentRequest $r): array
    {
        return [
            'reference'       => $r->reference,
            'amount'          => $r->amount,
            'currency'        => $r->currency->value,
            'description'     => $r->description,
            'customer_name'   => $r->customerName,
            'customer_email'  => $r->customerEmail,
            'customer_phone'  => $r->customerPhone,
            'callback_url'    => $r->callbackUrl,
            'return_url'      => $r->returnUrl,
            'idempotency_key' => $r->idempotencyKey,
            'metadata'        => $r->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function responseToArray(PaymentResponse $r): array
    {
        return [
            'success'                => $r->success,
            'gateway_transaction_id' => $r->gatewayTransactionId,
            'status'                 => $r->status->value,
            'amount'                 => $r->amount,
            'currency'               => $r->currency->value,
            'redirect_url'           => $r->redirectUrl,
            'qr_code'                => $r->qrCode,
            'deep_link'              => $r->deepLink,
            'readable_code'          => $r->readableCode,
            'expires_at'             => $r->expiresAt?->format(\DateTimeInterface::ATOM),
            'raw'                    => $r->raw,
        ];
    }
}
