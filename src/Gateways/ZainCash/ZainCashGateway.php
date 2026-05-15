<?php
declare(strict_types=1);

namespace Shah\Parakit\Gateways\ZainCash;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Shah\Parakit\Contracts\SupportsStatusCheck;
use Shah\Parakit\DTOs\PaymentRequest;
use Shah\Parakit\DTOs\PaymentResponse;
use Shah\Parakit\DTOs\WebhookPayload;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Enums\PaymentStatus;
use Shah\Parakit\Exceptions\GatewayUnavailableException;
use Shah\Parakit\Exceptions\InvalidWebhookSignatureException;
use Shah\Parakit\Gateways\AbstractGateway;

final class ZainCashGateway extends AbstractGateway implements SupportsStatusCheck
{
    private readonly ZainCashJwt $jwt;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);
        $this->jwt = new ZainCashJwt((string) $config['secret']);
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        $now = time();
        $claims = [
            'amount' => $request->amount,
            'serviceType' => $request->description,
            'msisdn' => (string) $this->config['msisdn'],
            'orderId' => $request->reference,
            'redirectUrl' => $request->returnUrl ?? (string) ($this->config['redirect_url'] ?? ''),
            'iat' => $now,
            'exp' => $now + 60 * 60 * 4,
            'lang' => (string) ($this->config['lang'] ?? 'en'),
        ];

        $token = $this->jwt->encode($claims);

        $res = Http::asForm()
            ->timeout((int) config('parakit.reliability.timeout_seconds', 15))
            ->post(rtrim((string) $this->config['base_url'], '/') . '/transaction/init', [
                'token' => $token,
                'merchantId' => (string) $this->config['merchant_id'],
                'lang' => (string) ($this->config['lang'] ?? 'en'),
            ]);

        if (!$res->successful() || !$res->json('id')) {
            throw new GatewayUnavailableException("ZainCash init failed: HTTP {$res->status()}");
        }

        $id = (string) $res->json('id');
        $redirect = rtrim((string) $this->config['base_url'], '/') . '/transaction/pay?id=' . $id;

        $body = $res->json();

        return new PaymentResponse(
            success: true,
            gateway: $this->name(),
            gatewayTransactionId: $id,
            reference: $request->reference,
            status: PaymentStatus::Pending,
            amount: $request->amount,
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            redirectUrl: $redirect,
            expiresAt: new DateTimeImmutable('@' . $claims['exp']),
            raw: is_array($body) ? $body : [],
        );
    }

    public function status(string $gatewayTransactionId): PaymentResponse
    {
        $now = time();
        $token = $this->jwt->encode([
            'id' => $gatewayTransactionId,
            'msisdn' => (string) $this->config['msisdn'],
            'iat' => $now,
            'exp' => $now + 60 * 5,
        ]);

        $res = Http::asForm()
            ->timeout((int) config('parakit.reliability.timeout_seconds', 15))
            ->post(rtrim((string) $this->config['base_url'], '/') . '/transaction/get', [
                'token' => $token,
                'merchantId' => (string) $this->config['merchant_id'],
            ]);

        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash status failed: HTTP {$res->status()}");
        }

        $raw = $res->json();
        $raw = is_array($raw) ? $raw : [];
        $status = $this->mapStatus((string) ($raw['status'] ?? ''));

        return new PaymentResponse(
            success: $status->isSuccessful() || $status === PaymentStatus::Pending,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: (string) ($raw['orderId'] ?? ''),
            status: $status,
            amount: (int) ($raw['amount'] ?? 0),
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            raw: $raw,
        );
    }

    /**
     * ZainCash signs the callback as a JWT in the `token` form field. The JWT
     * itself IS the signature — decoding with our shared HS256 secret rejects
     * any forged or tampered payload. Algorithm is pinned in ZainCashJwt.
     */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $token = (string) $request->input('token', '');
        if ($token === '') {
            throw new InvalidWebhookSignatureException('ZainCash webhook missing token');
        }

        $claims = $this->jwt->decode($token);

        $status = $this->mapStatus((string) ($claims['status'] ?? ''));
        $id = (string) ($claims['id'] ?? '');

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $id,
            reference: (string) ($claims['orderid'] ?? $claims['orderId'] ?? ''),
            status: $status,
            amount: (int) ($claims['amount'] ?? 0),
            currency: Currency::IQD,
            eventId: $id . ':' . $status->value,
            occurredAt: isset($claims['iat'])
                ? new DateTimeImmutable('@' . (int) $claims['iat'])
                : new DateTimeImmutable(),
            raw: $claims,
        );
    }

    private function mapStatus(string $raw): PaymentStatus
    {
        return match (strtolower($raw)) {
            'success', 'completed' => PaymentStatus::Paid,
            'failed'               => PaymentStatus::Failed,
            'cancelled', 'canceled'=> PaymentStatus::Cancelled,
            'expired'              => PaymentStatus::Expired,
            'pending'              => PaymentStatus::Pending,
            default                => PaymentStatus::Pending,
        };
    }
}
