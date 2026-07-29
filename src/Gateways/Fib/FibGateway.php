<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Fib;

use DateTimeImmutable;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Froshly\Parakit\Contracts\SupportsCancel;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\DTOs\RefundResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Gateways\AbstractGateway;
use Froshly\Parakit\Support\Money;

final class FibGateway extends AbstractGateway implements SupportsCancel, SupportsRefund, SupportsStatusCheck
{
    private readonly FibClient $client;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);

        $this->client = new FibClient(
            baseUrl: (string) $config['base_url'],
            tokens: new FibTokenCache(
                (string) $config['base_url'],
                (string) $config['client_id'],
                (string) $config['client_secret'],
            ),
            timeoutSeconds: (int) config('parakit.reliability.timeout_seconds', 15),
        );
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        if ($request->currency !== Currency::IQD) {
            throw new InvalidArgumentException(
                'FIB settles IQD only; got ' . $request->currency->value
            );
        }

        // FIB amount is a decimal string in MAJOR units.
        $params = [
            'amount' => Money::format($request->amount, $request->currency),
            'currency' => $request->currency->value,
            'description' => $request->description,
            'callback' => $request->callbackUrl ?? (string) ($this->config['callback_url'] ?? ''),
        ];

        if ($request->returnUrl !== null && $request->returnUrl !== '') {
            $params['redirectUri'] = $request->returnUrl;
        }

        // ISO-8601 durations (P7D, PT12H, ...). Per-request metadata overrides config.
        $refundableFor = $request->metadata['refundable_for'] ?? $this->config['refundable_for'] ?? null;
        if ($refundableFor !== null && $refundableFor !== '') {
            $params['refundableFor'] = (string) $refundableFor;
        }
        $expiresIn = $request->metadata['expires_in'] ?? $this->config['expires_in'] ?? null;
        if ($expiresIn !== null && $expiresIn !== '') {
            $params['expiresIn'] = (string) $expiresIn;
        }

        $category = $request->metadata['category'] ?? $this->config['category'] ?? null;
        if ($category !== null && $category !== '') {
            $params['category'] = (string) $category;
        }

        $raw = $this->client->createCharge($params);

        $status = isset($raw['status'])
            ? FibStatusMap::toStatus(
                (string) $raw['status'],
                isset($raw['decliningReason']) ? (string) $raw['decliningReason'] : null,
            )
            : PaymentStatus::Pending;

        return new PaymentResponse(
            success: true,
            gateway: $this->name(),
            gatewayTransactionId: (string) $raw['paymentId'],
            reference: $request->reference,
            status: $status,
            amount: $request->amount,
            currency: $request->currency,
            correlationId: $this->correlationId(),
            qrCode: $raw['qrCode'] ?? null,
            deepLink: $raw['personalAppLink']
                ?? $raw['businessAppLink']
                ?? $raw['corporateAppLink']
                ?? null,
            readableCode: $raw['readableCode'] ?? null,
            expiresAt: isset($raw['validUntil']) ? new DateTimeImmutable((string) $raw['validUntil']) : null,
            raw: $raw,
        );
    }

    public function status(string $gatewayTransactionId): PaymentResponse
    {
        $raw = $this->client->fetchStatus($gatewayTransactionId);
        [$status, $currency, $amount] = $this->parseStatusBody($raw);

        return new PaymentResponse(
            success: $status->isSuccessful() || $status === PaymentStatus::Pending,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: (string) ($raw['reference'] ?? ''),
            status: $status,
            amount: $amount,
            currency: $currency,
            correlationId: $this->correlationId(),
            raw: $raw,
        );
    }

    /** FIB cancel returns no body; re-fetch via status to get the post-cancel state. */
    public function cancel(string $gatewayTransactionId): PaymentResponse
    {
        $this->client->cancel($gatewayTransactionId);

        return $this->status($gatewayTransactionId);
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        return $this->refundIdempotent($request, fn () => $this->performRefund($request));
    }

    private function performRefund(RefundRequest $request): RefundResponse
    {
        $statusBody = $this->client->fetchStatus($request->transactionId);
        [, , $originalAmount] = $this->parseStatusBody($statusBody);
        if ($originalAmount <= 0) {
            throw new GatewayUnavailableException('FIB status returned no refundable amount');
        }
        if ($request->amount !== $originalAmount) {
            throw new InvalidArgumentException(
                'FIB supports full refunds only; refund amount must equal the original charge amount'
            );
        }

        $raw = $this->client->refund($request->transactionId);

        $refundId = $raw['refundId'] ?? null;

        return new RefundResponse(
            success: true,
            refundId: is_string($refundId) && $refundId !== '' ? $refundId : null,
            refundedAmount: $originalAmount,
            raw: $raw,
        );
    }

    /** FIB callbacks deliver only `{ id, status }`; the status endpoint is the trust boundary. */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $id = (string) $request->input('id', '');
        if ($id === '') {
            throw new InvalidWebhookSignatureException('FIB callback missing payment id');
        }

        try {
            $raw = $this->client->fetchStatus($id);
        } catch (\Throwable $e) {
            throw new InvalidWebhookSignatureException(
                'FIB status verification failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        [$status, $currency, $amount] = $this->parseStatusBody($raw);

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $id,
            reference: (string) ($raw['reference'] ?? ''),
            status: $status,
            amount: $amount,
            currency: $currency,
            eventId: $id . ':' . $status->value,
            occurredAt: new DateTimeImmutable(),
            raw: $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{0: PaymentStatus, 1: Currency, 2: int}
     */
    private function parseStatusBody(array $raw): array
    {
        $status = FibStatusMap::toStatus(
            (string) ($raw['status'] ?? ''),
            isset($raw['decliningReason']) ? (string) $raw['decliningReason'] : null,
        );
        $amountInfo = (array) ($raw['amount'] ?? []);
        $currency = Currency::tryFrom((string) ($amountInfo['currency'] ?? 'IQD')) ?? Currency::IQD;
        $rawAmount = (string) ($amountInfo['amount'] ?? '0');
        $amount = preg_match('/^\d+(\.\d+)?$/', $rawAmount) === 1
            ? Money::parse($rawAmount, $currency)
            : 0;

        return [$status, $currency, $amount];
    }

    protected function retryChargeOnTransientFailure(): bool
    {
        return false;
    }
}
