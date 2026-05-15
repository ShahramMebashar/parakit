<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Nass;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Gateways\AbstractGateway;
use Froshly\Parakit\Support\IdempotencyKey;
use Froshly\Parakit\Support\Money;

final class NassGateway extends AbstractGateway implements SupportsStatusCheck
{
    private readonly NassClient $client;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);

        $this->client = new NassClient(
            baseUrl: (string) $config['base_url'],
            tokens: new NassTokenCache(
                (string) $config['base_url'],
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                (int) ($config['token_ttl'] ?? 3000),
            ),
            timeoutSeconds: (int) config('parakit.reliability.timeout_seconds', 15),
        );
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        // Recompute the same stable idempotency key AbstractGateway uses, then
        // derive a numeric NassPay orderId from it. crc32 is deterministic, so
        // a retried performCharge re-sends the SAME orderId and never creates a
        // duplicate NassPay transaction.
        $idemKey = $request->idempotencyKey ?? IdempotencyKey::derive(
            $this->name(),
            $request->reference,
            $request->amount,
            $request->currency->value,
        );
        $orderId = (string) crc32($idemKey);

        $payload = [
            'orderId' => $orderId,
            'orderDesc' => $request->description,
            // NassPay expects the amount in MAJOR units as a string.
            'amount' => Money::format($request->amount, $request->currency),
            'currency' => NassCurrencyMap::toCode($request->currency),
            'transactionType' => (int) ($this->config['transaction_type'] ?? 1),
            'backRef' => $request->returnUrl ?? (string) ($this->config['return_url'] ?? ''),
            'notifyUrl' => $request->callbackUrl ?? (string) ($this->config['callback_url'] ?? ''),
        ];

        $raw = $this->client->initTransaction($payload);
        $data = (array) ($raw['data'] ?? []);

        $url = $data['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new GatewayUnavailableException('NassPay init returned no redirect url');
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
        $raw = $this->client->checkStatus($gatewayTransactionId);
        [$status, $currency, $amount] = $this->parseStatusData($raw);

        return new PaymentResponse(
            success: $status->isSuccessful() || $status === PaymentStatus::Pending,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: '',
            status: $status,
            amount: $amount,
            currency: $currency,
            correlationId: $this->correlationId(),
            raw: $raw,
        );
    }

    /**
     * Shared parser for a NassPay checkStatus body. Returns
     * [PaymentStatus, Currency, amount-in-minor-units].
     *
     * @param array<string, mixed> $raw
     * @return array{0: PaymentStatus, 1: Currency, 2: int}
     */
    private function parseStatusData(array $raw): array
    {
        $data = (array) ($raw['data'] ?? []);
        $status = NassStatusMap::toStatus((string) ($data['responseCode'] ?? ''));
        $currency = NassCurrencyMap::fromCode((string) ($data['currency'] ?? '368'))
            ?? Currency::IQD;

        $rawAmount = (string) ($data['amount'] ?? '0');
        $amount = preg_match('/^\d+(\.\d+)?$/', $rawAmount) === 1
            ? Money::parse($rawAmount, $currency)
            : 0;

        return [$status, $currency, $amount];
    }

    /**
     * NassPay callbacks carry no signature, so the callback body cannot be
     * trusted on its own. The trust boundary is the checkStatus endpoint: we
     * read only the orderId from the callback, then re-fetch the authoritative
     * state server-to-server. Any failure on that call is a verification
     * failure (401 at the controller).
     */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $orderId = (string) $request->input('orderId', '');
        if ($orderId === '') {
            throw new InvalidWebhookSignatureException('NassPay callback missing orderId');
        }

        try {
            $raw = $this->client->checkStatus($orderId);
        } catch (\Throwable $e) {
            throw new InvalidWebhookSignatureException(
                'NassPay status verification failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        [$status, $currency, $amount] = $this->parseStatusData($raw);

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $orderId,
            reference: '',
            status: $status,
            amount: $amount,
            currency: $currency,
            eventId: $orderId . ':' . $status->value,
            occurredAt: new DateTimeImmutable(),
            raw: $raw,
        );
    }
}
