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
        // QiCard settles IQD only. Reject other currencies up front rather
        // than silently charging IQD while echoing back the requested currency.
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

        // QiCard caps additionalInfo at 10 string-typed properties. Cast all
        // values to strings and slice — silently ignoring rather than
        // exploding lets metadata-heavy callers stay compatible.
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

    /**
     * Cancel an active QiCard payment. The cancel response carries the full
     * payment object plus a `cancels[]` array of attempts; we use the
     * top-level `canceled` flag combined with the latest attempt to decide
     * whether the payment is now terminally Cancelled or still Pending.
     */
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
        $body = [
            'requestId' => $this->newRequestId(),
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
     * QiCard webhook handling has two modes:
     *
     *   1. **Verified**: when `qicard.public_key` is configured, the
     *      X-Signature header is mandatory and verified via
     *      QiCardSignatureVerifier. Tampered or missing signatures throw
     *      InvalidWebhookSignatureException (→ 401 at the controller).
     *
     *   2. **Unverified-with-fallback**: when no public key is configured we
     *      cannot prove authenticity, so we re-fetch the payment status
     *      server-to-server and trust that body instead. A
     *      `parakit.qicard.webhook.unverified` warning is logged for every
     *      such request so operators see this is happening.
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
     * Resolve the authoritative body for a webhook: either the verified
     * inbound payload, or a server-to-server status re-fetch when no public
     * key is configured.
     *
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
     * Parse a QiCard payment or status body.
     *
     * @param array<string, mixed> $raw
     * @return array{0: PaymentStatus, 1: int}
     */
    private function parseStatusBody(array $raw): array
    {
        $status = QiCardStatusMap::toStatus((string) ($raw['status'] ?? ''));

        // confirmedAmount is the post-3DS settled value when present;
        // amount is the original create-time value. Either is acceptable.
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
            // QiCard's cancel response sometimes omits the cancels[] array
            // entirely; in that case `canceled: true` at the top level is
            // authoritative.
            return true;
        }

        $last = $cancels[array_key_last($cancels)];
        if (!is_array($last)) {
            return false;
        }

        return (bool) ($last['successfully'] ?? false);
    }

    /**
     * QiCard accepts `requestId` up to 36 chars. IdempotencyKey::derive
     * produces a 64-char sha256 hex — taking the first 36 chars keeps it
     * within the constraint AND stable across retries, so a retried charge
     * sends the same requestId QiCard already saw (→ no duplicate payment).
     */
    private function deriveRequestId(PaymentRequest $request): string
    {
        $key = $request->idempotencyKey ?? IdempotencyKey::derive(
            $this->name(),
            $request->reference,
            $request->amount,
            $request->currency->value,
        );

        return substr($key, 0, 36);
    }

    /**
     * Generate a fresh requestId for a non-idempotent operation
     * (cancel / refund). Each call MUST be unique per QiCard's terminal
     * constraints; Str::uuid() yields a 36-char UUIDv4 which fits.
     */
    private function newRequestId(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Format a parakit int amount (in IQD minor units, factor=1) as the
     * 2-decimal string QiCard's REST bodies expect. The webhook signature
     * canonical string uses 3 decimals — see QiCardSignatureVerifier.
     */
    private function amountString(int $minor): string
    {
        return number_format($minor, 2, '.', '');
    }

    /**
     * Build the optional customerInfo block from parakit's flat
     * PaymentRequest fields. parakit carries `customerName` as a single
     * string; we route the whole thing into `firstName` rather than guess
     * at a name split.
     *
     * @return array<string, string>
     */
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
