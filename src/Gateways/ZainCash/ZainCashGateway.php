<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use DateTimeImmutable;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
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
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Support\IdempotencyKey;

final class ZainCashGateway extends AbstractGateway implements SupportsStatusCheck, SupportsRefund
{
    private readonly ZainCashJwt $jwt;
    private readonly ZainCashClient $client;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);

        // v2 uses two distinct secrets: api_key verifies callback JWTs, client_secret is for OAuth2.
        $this->jwt = new ZainCashJwt((string) $config['api_key']);
        $this->client = new ZainCashClient(
            baseUrl: (string) $config['base_url'],
            tokens: new ZainCashTokenCache(
                (string) $config['base_url'],
                (string) $config['client_id'],
                (string) $config['client_secret'],
                (string) ($config['scope'] ?? 'payment:read payment:write reverse:write'),
            ),
            timeoutSeconds: (int) config('parakit.reliability.timeout_seconds', 15),
        );
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        if ($request->currency !== Currency::IQD) {
            throw new InvalidArgumentException(
                'ZainCash settles IQD only; got ' . $request->currency->value
            );
        }

        // externalReferenceId must be stable across retries; derive a UUIDv5 from the idempotency key.
        $idemKey = IdempotencyKey::localForRequest($this->name(), $request);
        $externalReferenceId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'parakit:zaincash:' . $idemKey,
        )->toString();

        $serviceType = (string) ($request->metadata['service_type']
            ?? $this->config['service_type']
            ?? 'Delivery');

        $payload = [
            'language' => $this->normalizeLang((string) ($this->config['lang'] ?? 'en')),
            'externalReferenceId' => $externalReferenceId,
            'orderId' => $request->reference,
            'serviceType' => $serviceType,
            'amount' => [
                'value' => (string) $request->amount,
                'currency' => Currency::IQD->value,
            ],
            'redirectUrls' => [
                'successUrl' => $request->returnUrl ?? (string) ($this->config['success_url'] ?? ''),
                'failureUrl' => (string) ($this->config['failure_url'] ?? ''),
            ],
        ];
        if ($request->customerPhone !== null && $request->customerPhone !== '') {
            $payload['customer'] = ['phone' => $request->customerPhone];
        }

        try {
            $raw = $this->client->init($payload);
        } catch (ZainCashApiException $e) {
            $transactionId = data_get($e->response, 'transactionDetails.transactionId');
            $errorText = strtoupper($e->apiCode . ' ' . $e->getMessage());
            $duplicate = str_contains($errorText, 'DUPLICAT')
                || str_contains($errorText, 'ALREADY_EXISTS');

            if (! $duplicate || ! is_string($transactionId) || $transactionId === '') {
                throw $e;
            }

            $raw = $this->client->inquiry($transactionId);
        }

        $transactionId = $raw['transactionDetails']['transactionId'] ?? null;
        $redirectUrl = $raw['redirectUrl'] ?? null;
        if (!is_string($transactionId) || $transactionId === ''
            || !is_string($redirectUrl) || $redirectUrl === '') {
            throw new GatewayUnavailableException(
                'ZainCash init returned no transactionId/redirectUrl'
            );
        }

        return new PaymentResponse(
            success: true,
            gateway: $this->name(),
            gatewayTransactionId: $transactionId,
            reference: $request->reference,
            status: PaymentStatus::Pending,
            amount: $request->amount,
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            redirectUrl: $redirectUrl,
            expiresAt: isset($raw['expiryTime'])
                ? new DateTimeImmutable((string) $raw['expiryTime'])
                : null,
            raw: $raw,
        );
    }

    public function status(string $gatewayTransactionId): PaymentResponse
    {
        $raw = $this->client->inquiry($gatewayTransactionId);

        $status = ZainCashStatusMap::toStatus((string) ($raw['status'] ?? ''));
        $details = (array) ($raw['transactionDetails'] ?? []);
        $amountInfo = (array) ($details['amount'] ?? []);

        return new PaymentResponse(
            success: $status->isSuccessful() || $status === PaymentStatus::Pending,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: (string) ($details['orderId'] ?? ''),
            status: $status,
            amount: (int) ($amountInfo['value'] ?? 0),
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            raw: $raw,
        );
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        return $this->refundIdempotent($request, fn () => $this->performRefund($request));
    }

    private function performRefund(RefundRequest $request): RefundResponse
    {
        // v2 reverse is full-refund only; reject partial refunds before touching the gateway.
        $tx = PaymentTransaction::query()
            ->where('gateway', $this->name())
            ->where('gateway_transaction_id', $request->transactionId)
            ->first();
        if ($tx !== null && (int) $tx->amount !== $request->amount) {
            throw new \InvalidArgumentException(
                'ZainCash supports full reversals only; refund amount must equal the original charge amount'
            );
        }

        $raw = $this->client->reverse(
            $request->transactionId,
            $request->reason ?? 'Merchant-initiated reversal',
        );

        $reverseStatus = strtoupper((string) ($raw['status'] ?? ''));
        $refundId = $raw['reversalReferenceId'] ?? null;
        if ($reverseStatus !== 'COMPLETED' || !is_string($refundId) || $refundId === '') {
            throw new GatewayUnavailableException(
                "ZainCash reverse did not complete (status: {$reverseStatus})"
            );
        }

        return new RefundResponse(
            success: true,
            refundId: $refundId,
            refundedAmount: $request->amount,
            raw: $raw,
        );
    }

    /** Redirect (`?token=`) and webhook (`{webhook_token}`) both deliver an HS256 JWT signed with api_key. */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $token = (string) ($request->input('webhook_token')
            ?? $request->input('token')
            ?? '');
        if ($token === '') {
            throw new InvalidWebhookSignatureException('ZainCash callback missing token');
        }

        $claims = $this->jwt->decode($token);
        $data = (array) ($claims['data'] ?? []);
        $eventType = strtoupper((string) ($claims['eventType'] ?? ''));
        $currentStatus = (string) ($data['currentStatus'] ?? '');

        $status = match ($eventType) {
            'STATUS_CHANGED'   => ZainCashStatusMap::toStatus($currentStatus),
            'REFUND_COMPLETED' => PaymentStatus::Refunded,
            'REFUND_FAILED'    => $this->onRefundFailed($currentStatus),
            default            => $this->onUnknownEvent($eventType, $currentStatus),
        };

        $amountInfo = (array) ($data['amount'] ?? []);
        $transactionId = (string) ($data['transactionId'] ?? '');

        // Prefer ZainCash's eventId; fall back to a derived key only if absent.
        $eventId = (string) ($claims['eventId'] ?? '');
        if ($eventId === '') {
            $eventId = $transactionId . ':' . $status->value;
        }

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $transactionId,
            reference: (string) ($data['orderId'] ?? ''),
            status: $status,
            amount: (int) ($amountInfo['value'] ?? 0),
            currency: Currency::IQD,
            eventId: $eventId,
            occurredAt: isset($claims['timestamp'])
                ? new DateTimeImmutable((string) $claims['timestamp'])
                : new DateTimeImmutable(),
            raw: $claims,
        );
    }

    /** REFUND_FAILED leaves the payment unchanged; log and map currentStatus normally. */
    private function onRefundFailed(string $currentStatus): PaymentStatus
    {
        Log::warning('parakit.zaincash.refund_failed', ['currentStatus' => $currentStatus]);
        return ZainCashStatusMap::toStatus($currentStatus);
    }

    private function onUnknownEvent(string $eventType, string $currentStatus): PaymentStatus
    {
        Log::warning('parakit.zaincash.unknown_event', ['eventType' => $eventType]);
        return ZainCashStatusMap::toStatus($currentStatus);
    }

    /** v2 documented contract is title-case (En/Ar/Ku) even though curl examples use lowercase. */
    private function normalizeLang(string $lang): string
    {
        return match (strtolower($lang)) {
            'ar' => 'Ar',
            'ku' => 'Ku',
            default => 'En',
        };
    }
}
