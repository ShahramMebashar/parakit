<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Nass;

use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Gateways\AbstractGateway;
use Froshly\Parakit\Support\IdempotencyKey;
use Froshly\Parakit\Support\Money;

final class NassGateway extends AbstractGateway
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

    public function handleWebhook(\Illuminate\Http\Request $request): \Froshly\Parakit\DTOs\WebhookPayload
    {
        // Implemented in Task 10.
        throw new \LogicException('NassGateway::handleWebhook not yet implemented');
    }
}
