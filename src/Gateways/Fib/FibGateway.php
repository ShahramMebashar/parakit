<?php
declare(strict_types=1);

namespace Shah\Parakit\Gateways\Fib;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Shah\Parakit\Contracts\SupportsRefund;
use Shah\Parakit\Contracts\SupportsStatusCheck;
use Shah\Parakit\DTOs\PaymentRequest;
use Shah\Parakit\DTOs\PaymentResponse;
use Shah\Parakit\DTOs\RefundRequest;
use Shah\Parakit\DTOs\RefundResponse;
use Shah\Parakit\DTOs\WebhookPayload;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Enums\PaymentStatus;
use Shah\Parakit\Exceptions\GatewayUnavailableException;
use Shah\Parakit\Exceptions\InvalidWebhookSignatureException;
use Shah\Parakit\Gateways\AbstractGateway;
use Shah\Parakit\Support\Money;

final class FibGateway extends AbstractGateway implements SupportsRefund, SupportsStatusCheck
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
        // FIB's `monetaryValue.amount` is a decimal string in MAJOR units.
        // Convert minor-unit integers (our canonical DTO shape) before sending.
        $raw = $this->client->createCharge([
            'amount' => Money::format($request->amount, $request->currency),
            'currency' => $request->currency->value,
            'description' => $request->description,
            'callback' => $request->callbackUrl ?? (string) ($this->config['callback_url'] ?? ''),
        ]);

        // Trust an explicit status if FIB included one in the create response,
        // otherwise default to Pending (the QR/deep-link/readable-code flow).
        $status = isset($raw['status'])
            ? FibStatusMap::toStatus((string) $raw['status'])
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
            deepLink: $raw['personalAppLink'] ?? null,
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

    public function refund(RefundRequest $request): RefundResponse
    {
        $raw = $this->client->refund($request->transactionId);

        $refundId = $raw['refundId'] ?? null;
        if (!is_string($refundId) || $refundId === '') {
            throw new GatewayUnavailableException(
                'FIB refund returned 200 without a refundId — treating as failure'
            );
        }

        return new RefundResponse(
            success: true,
            refundId: $refundId,
            refundedAmount: $request->amount,
            raw: $raw,
        );
    }

    /**
     * FIB callbacks deliver only `{ id, status }`. The trust boundary is the
     * status endpoint, not the callback body — so we re-fetch server-to-server
     * using our authenticated client. A missing id or any failure on the
     * status call is treated as a verification failure (401 at the controller).
     */
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
     * Shared parser for FIB status payloads. Returns [status, currency, amount].
     * Unknown currencies fall back to IQD (logged in FibStatusMap path; here we
     * just don't blow up — the spec only supports IQD/USD today).
     *
     * @param array<string, mixed> $raw
     * @return array{0: PaymentStatus, 1: Currency, 2: int}
     */
    private function parseStatusBody(array $raw): array
    {
        $status = FibStatusMap::toStatus((string) ($raw['status'] ?? ''));
        $amountInfo = (array) ($raw['amount'] ?? []);
        $currency = Currency::tryFrom((string) ($amountInfo['currency'] ?? 'IQD')) ?? Currency::IQD;
        $rawAmount = (string) ($amountInfo['amount'] ?? '0');
        $amount = preg_match('/^\d+(\.\d+)?$/', $rawAmount) === 1
            ? Money::parse($rawAmount, $currency)
            : 0;

        return [$status, $currency, $amount];
    }
}
