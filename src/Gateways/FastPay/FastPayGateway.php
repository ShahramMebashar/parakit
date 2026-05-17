<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\FastPay;

use Illuminate\Http\Request;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\DTOs\PaymentError;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\DTOs\RefundResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Exceptions\PaymentException;
use Froshly\Parakit\Gateways\AbstractGateway;
use Froshly\Parakit\Support\IdempotencyKey;
use Froshly\Parakit\Support\Money;

final class FastPayGateway extends AbstractGateway implements SupportsStatusCheck, SupportsRefund
{
    private readonly FastPayClient $client;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);

        $this->client = new FastPayClient(
            baseUrl: (string) $config['base_url'],
            timeoutSeconds: (int) config('parakit.reliability.timeout_seconds', 15),
        );
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        $orderId = $this->deriveOrderId($request);

        $raw = $this->client->initiation($this->credentials() + [
            'order_id'     => $orderId,
            'bill_amount'  => $request->amount,
            'currency'     => 'IQD',
            'success_url'  => $request->returnUrl ?? (string) ($this->config['success_url'] ?? ''),
            'cancel_url'   => (string) ($this->config['cancel_url'] ?? ''),
            'callback_url' => $request->callbackUrl ?? (string) ($this->config['callback_url'] ?? ''),
            // FastPay's docs type `cart` as a string and the worked example
            // sends a JSON-encoded string, so we encode rather than pass an array.
            'cart' => (string) json_encode([[
                'name'       => $request->description,
                'qty'        => 1,
                'unit_price' => $request->amount,
                'sub_total'  => $request->amount,
            ]]),
        ]);

        $data = (array) ($raw['data'] ?? []);
        $url = $data['redirect_uri'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new GatewayUnavailableException('FastPay initiation returned no redirect_uri');
        }

        return new PaymentResponse(
            success: true,
            gateway: $this->name(),
            gatewayTransactionId: $orderId,
            reference: $request->reference,
            status: PaymentStatus::Pending,
            amount: $request->amount,
            currency: $request->currency,
            correlationId: $this->correlationId(),
            redirectUrl: $url,
            raw: $raw,
        );
    }

    public function status(string $gatewayTransactionId): PaymentResponse
    {
        try {
            $raw = $this->client->validate($this->credentials() + [
                'order_id' => $gatewayTransactionId,
            ]);
        } catch (PaymentException $e) {
            // FastPay answers code 404 for an order it has not seen a payment
            // for — that is "not paid yet", not a failure. Report Pending.
            return $this->statusResponse($gatewayTransactionId, PaymentStatus::Pending, 0, []);
        }

        [$status, $amount] = $this->parseValidateData($raw);

        return $this->statusResponse($gatewayTransactionId, $status, $amount, $raw);
    }

    /**
     * FastPay refunds are a push to a FastPay wallet, so they need the
     * recipient's mobile number. RefundRequest carries no msisdn, so we first
     * call validate to read the original payer's customer_mobile_number — a
     * refund therefore always returns funds to whoever paid.
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        try {
            $validated = $this->client->validate($this->credentials() + [
                'order_id' => $request->transactionId,
            ]);
            $msisdn = (string) (((array) ($validated['data'] ?? []))['customer_mobile_number'] ?? '');

            $raw = $this->client->refund($this->credentials() + [
                'order_id'          => $request->transactionId,
                'amount'            => $request->amount,
                'refund_secret_key' => (string) ($this->config['refund_secret_key'] ?? ''),
                'msisdn'            => $msisdn,
            ]);
        } catch (PaymentException $e) {
            return new RefundResponse(
                success: false,
                refundId: null,
                refundedAmount: 0,
                error: new PaymentError(
                    code: FastPayErrorMap::toCode($e->getMessage()),
                    rawCode: 'fastpay_refund_rejected',
                    rawMessage: $e->getMessage(),
                ),
            );
        }

        $invoiceId = ((array) (((array) ($raw['data'] ?? []))['summary'] ?? []))['invoice_id'] ?? null;

        return new RefundResponse(
            success: true,
            refundId: is_string($invoiceId) && $invoiceId !== '' ? $invoiceId : null,
            refundedAmount: $request->amount,
            raw: $raw,
        );
    }

    /**
     * FastPay's IPN carries no signature, so the notification body cannot be
     * trusted on its own. The trust boundary is the validate endpoint: we read
     * only the order_id from the IPN, then re-fetch the authoritative state
     * server-to-server. Any failure on that call — including a not-found
     * order — is a verification failure (401 at the controller).
     */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $orderId = (string) $request->input('order_id', '');
        if ($orderId === '') {
            throw new InvalidWebhookSignatureException('FastPay IPN missing order_id');
        }

        try {
            $raw = $this->client->validate($this->credentials() + ['order_id' => $orderId]);
        } catch (\Throwable $e) {
            throw new InvalidWebhookSignatureException(
                'FastPay IPN verification failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        [$status, $amount] = $this->parseValidateData($raw);

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $orderId,
            reference: '',
            status: $status,
            amount: $amount,
            currency: Currency::IQD,
            eventId: $orderId . ':' . $status->value,
            occurredAt: new \DateTimeImmutable(),
            raw: $raw,
        );
    }

    /**
     * Parse a FastPay validate body. Returns [PaymentStatus, amount-in-minor-units].
     *
     * @param array<string, mixed> $raw
     * @return array{0: PaymentStatus, 1: int}
     */
    private function parseValidateData(array $raw): array
    {
        $data = (array) ($raw['data'] ?? []);
        $status = FastPayStatusMap::toStatus((string) ($data['status'] ?? ''));

        $rawAmount = (string) ($data['received_amount'] ?? '0');
        $amount = preg_match('/^\d+(\.\d+)?$/', $rawAmount) === 1
            ? Money::parse($rawAmount, Currency::IQD)
            : 0;

        return [$status, $amount];
    }

    /** @param array<string, mixed> $raw */
    private function statusResponse(
        string $gatewayTransactionId,
        PaymentStatus $status,
        int $amount,
        array $raw,
    ): PaymentResponse {
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
     * FastPay's order_id must be 8-32 alphanumeric characters and identical
     * across charge retries. The shared idempotency key is a 64-char sha256
     * hex digest (alphanumeric); the first 24 chars satisfy the constraint and
     * stay stable because AbstractGateway re-derives the same key on retry.
     */
    private function deriveOrderId(PaymentRequest $request): string
    {
        $idemKey = $request->idempotencyKey ?? IdempotencyKey::derive(
            $this->name(),
            $request->reference,
            $request->amount,
            $request->currency->value,
        );

        return substr($idemKey, 0, 24);
    }

    /** @return array<string, string> */
    private function credentials(): array
    {
        return [
            'store_id'       => (string) ($this->config['store_id'] ?? ''),
            'store_password' => (string) ($this->config['store_password'] ?? ''),
        ];
    }
}
