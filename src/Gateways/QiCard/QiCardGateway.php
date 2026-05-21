<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\QiCard;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Froshly\Parakit\Contracts\SupportsCancel;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\DTOs\PaymentError;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\DTOs\RefundResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentErrorCode;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Gateways\AbstractGateway;
use Froshly\Parakit\Support\IdempotencyKey;
use Froshly\Parakit\Support\Money;

final class QiCardGateway extends AbstractGateway implements SupportsStatusCheck, SupportsRefund, SupportsCancel
{
    private readonly QiCardClient $client;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);

        $this->client = new QiCardClient(
            baseUrl: (string) $config['base_url'],
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            terminalId: (string) ($config['terminal_id'] ?? ''),
            timeoutSeconds: (int) config('parakit.reliability.timeout_seconds', 15),
        );
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        if ($request->currency !== Currency::IQD) {
            throw new InvalidArgumentException(
                'QiCard settles IQD only; got ' . $request->currency->value
            );
        }

        $body = [
            'requestId'        => $this->deriveRequestId($request),
            'amount'           => $this->amountString($request->amount),
            'currency'         => $request->currency->value,
            'finishPaymentUrl' => $request->returnUrl ?? (string) ($this->config['finish_payment_url'] ?? ''),
            'notificationUrl'  => $request->callbackUrl ?? (string) ($this->config['notification_url'] ?? ''),
        ];

        $locale = (string) ($this->config['locale'] ?? '');
        if ($locale !== '') {
            $body['locale'] = $locale;
        }

        $customer = $this->customerInfo($request);
        if ($customer !== []) {
            $body['customerInfo'] = $customer;
        }

        // additionalInfo caps at 10 string-typed properties.
        if ($request->metadata !== []) {
            $info = [];
            foreach ($request->metadata as $k => $v) {
                $info[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
                if (count($info) >= 10) {
                    break;
                }
            }
            $body['additionalInfo'] = $info;
        }

        $raw = $this->client->createPayment($body);

        $paymentId = (string) ($raw['paymentId'] ?? '');
        if ($paymentId === '') {
            throw new GatewayUnavailableException('QiCard /payment returned no paymentId');
        }

        $formUrl = isset($raw['formUrl']) && is_string($raw['formUrl']) ? $raw['formUrl'] : null;

        return new PaymentResponse(
            success: true,
            gateway: $this->name(),
            gatewayTransactionId: $paymentId,
            reference: $request->reference,
            status: QiCardStatusMap::toStatus((string) ($raw['status'] ?? 'CREATED')),
            amount: $request->amount,
            currency: $request->currency,
            correlationId: $this->correlationId(),
            redirectUrl: $formUrl,
            raw: $raw,
        );
    }

    public function status(string $gatewayTransactionId): PaymentResponse
    {
        $raw = $this->client->getStatus($gatewayTransactionId);

        [$status, $amount] = $this->parseStatusBody($raw);

        return new PaymentResponse(
            success: $status->isSuccessful() || $status === PaymentStatus::Pending,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: '',
            status: $status,
            amount: $amount,
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            raw: $raw,
        );
    }

    /** Cancel response carries `canceled` plus `cancels[]`; both must agree to mark terminally Cancelled. */
    public function cancel(string $gatewayTransactionId): PaymentResponse
    {
        $raw = $this->client->cancel($gatewayTransactionId, [
            'requestId' => $this->newRequestId(),
        ]);

        $canceled = (bool) ($raw['canceled'] ?? false);
        $lastAttemptOk = $this->lastCancelOk($raw);

        $status = $canceled && $lastAttemptOk
            ? PaymentStatus::Cancelled
            : QiCardStatusMap::toStatus((string) ($raw['status'] ?? 'CREATED'));

        [, $amount] = $this->parseStatusBody($raw);

        return new PaymentResponse(
            success: $status === PaymentStatus::Cancelled,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: '',
            status: $status,
            amount: $amount,
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
        // Keyed refunds reuse the same QiCard requestId; unkeyed remain unique per call.
        $requestId = $request->idempotencyKey !== null
            ? IdempotencyKey::gatewayHexPrefixForOperation($this->name(), 'refund', $request->idempotencyKey, 36)
            : $this->newRequestId();

        $body = [
            'requestId' => $requestId,
            'amount'    => $this->amountString($request->amount),
        ];
        if ($request->reason !== null && $request->reason !== '') {
            $body['message'] = $request->reason;
        }

        try {
            $raw = $this->client->refund($request->transactionId, $body);
        } catch (QiCardApiException $e) {
            return new RefundResponse(
                success: false,
                refundId: null,
                refundedAmount: 0,
                error: new PaymentError(
                    code: QiCardErrorMap::toCode($e->apiCode),
                    rawCode: (string) $e->apiCode,
                    rawMessage: $e->getMessage(),
                ),
            );
        }

        $outcome = QiCardStatusMap::refundOutcome((string) ($raw['status'] ?? ''));
        if ($outcome === false) {
            $details = is_array($raw['details'] ?? null) ? $raw['details'] : [];

            return new RefundResponse(
                success: false,
                refundId: null,
                refundedAmount: 0,
                error: new PaymentError(
                    code: PaymentErrorCode::Unknown,
                    rawCode: (string) ($details['resultCode'] ?? ''),
                    rawMessage: (string) ($details['resultDescription'] ?? 'QiCard refund failed'),
                ),
                raw: $raw,
            );
        }

        $refundId = $raw['refundId'] ?? null;

        return new RefundResponse(
            success: true,
            refundId: is_string($refundId) && $refundId !== '' ? $refundId : null,
            refundedAmount: $request->amount,
            raw: $raw,
        );
    }

    /**
     * Two modes: with `qicard.public_key`, X-Signature is mandatory and verified;
     * without it, re-fetch via getStatus and log `parakit.qicard.webhook.unverified`.
     */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            throw new InvalidWebhookSignatureException('QiCard webhook body is empty or non-JSON');
        }

        $paymentId = (string) ($payload['paymentId'] ?? '');
        if ($paymentId === '') {
            throw new InvalidWebhookSignatureException('QiCard webhook missing paymentId');
        }

        $authoritative = $this->authoritativeBody($paymentId, $payload, $request->header('X-Signature'));
        [$status, $amount] = $this->parseStatusBody($authoritative);

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $paymentId,
            reference: '',
            status: $status,
            amount: $amount,
            currency: Currency::IQD,
            eventId: $paymentId . ':' . strtoupper((string) ($authoritative['status'] ?? '')),
            occurredAt: $this->parseOccurredAt((string) ($authoritative['creationDate'] ?? '')),
            raw: $authoritative,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function authoritativeBody(string $paymentId, array $payload, mixed $signatureHeader): array
    {
        $publicKey = (string) ($this->config['public_key'] ?? '');

        if ($publicKey !== '') {
            QiCardSignatureVerifier::verify(
                is_string($signatureHeader) ? $signatureHeader : null,
                $payload,
                $publicKey,
            );
            return $payload;
        }

        Log::warning('parakit.qicard.webhook.unverified', [
            'paymentId' => $paymentId,
            'reason'    => 'public_key not configured; falling back to status re-check',
        ]);

        try {
            return $this->client->getStatus($paymentId);
        } catch (\Throwable $e) {
            throw new InvalidWebhookSignatureException(
                'QiCard webhook fallback verification failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{0: PaymentStatus, 1: int}
     */
    private function parseStatusBody(array $raw): array
    {
        $status = QiCardStatusMap::toStatus((string) ($raw['status'] ?? ''));

        // confirmedAmount is the post-3DS settled value; amount is create-time.
        $rawAmount = $raw['confirmedAmount'] ?? $raw['amount'] ?? null;
        $amount = is_numeric($rawAmount)
            ? (int) round((float) $rawAmount * Currency::IQD->minorUnitFactor())
            : 0;

        return [$status, $amount];
    }

    /** @param array<string, mixed> $raw */
    private function lastCancelOk(array $raw): bool
    {
        $cancels = $raw['cancels'] ?? null;
        if (!is_array($cancels) || $cancels === []) {
            // When cancels[] is omitted, top-level `canceled: true` is authoritative.
            return true;
        }

        $last = $cancels[array_key_last($cancels)];
        if (!is_array($last)) {
            return false;
        }

        return (bool) ($last['successfully'] ?? false);
    }

    /** QiCard requestId is capped at 36 chars and must be stable across charge retries. */
    private function deriveRequestId(PaymentRequest $request): string
    {
        return IdempotencyKey::gatewayHexPrefixForRequest($this->name(), $request, 36);
    }

    /** Each non-idempotent call (cancel/refund) requires a unique 36-char requestId. */
    private function newRequestId(): string
    {
        return Str::uuid()->toString();
    }

    /** REST bodies use 2 decimals; the webhook signature canonical string uses 3 (see QiCardSignatureVerifier). */
    private function amountString(int $minor): string
    {
        return number_format($minor, 2, '.', '');
    }

    /** @return array<string, string> */
    private function customerInfo(PaymentRequest $request): array
    {
        $info = [];
        if ($request->customerName !== null && $request->customerName !== '') {
            $info['firstName'] = $request->customerName;
        }
        if ($request->customerPhone !== null && $request->customerPhone !== '') {
            $info['phone'] = $request->customerPhone;
        }
        if ($request->customerEmail !== null && $request->customerEmail !== '') {
            $info['email'] = $request->customerEmail;
        }
        return $info;
    }

    private function parseOccurredAt(string $iso): DateTimeImmutable
    {
        if ($iso === '') {
            return new DateTimeImmutable();
        }
        try {
            return new DateTimeImmutable($iso);
        } catch (Exception) {
            return new DateTimeImmutable();
        }
    }
}
