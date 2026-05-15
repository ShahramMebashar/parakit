<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Fib;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

final class FibClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly FibTokenCache $tokens,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createCharge(array $params): array
    {
        $payload = [
            'monetaryValue' => [
                'amount' => (string) $params['amount'],
                'currency' => $params['currency'],
            ],
            'description' => (string) ($params['description'] ?? ''),
            // FIB's create-payment field is `statusCallbackUrl` — the webhook
            // URL FIB POSTs to on status changes.
            'statusCallbackUrl' => (string) ($params['callback'] ?? ''),
        ];
        foreach (['statementDate', 'expiresIn', 'refundableFor', 'refusalDescription'] as $opt) {
            if (isset($params[$opt])) {
                $payload[$opt] = $params[$opt];
            }
        }

        $res = $this->client()->post('/protected/v1/payments', $payload);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB charge failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function fetchStatus(string $paymentId): array
    {
        $res = $this->client()->get("/protected/v1/payments/{$paymentId}/status");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB status failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function refund(string $paymentId): array
    {
        $res = $this->client()->post("/protected/v1/payments/{$paymentId}/refund");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB refund failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function cancel(string $paymentId): array
    {
        $res = $this->client()->post("/protected/v1/payments/{$paymentId}/cancel");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB cancel failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->tokens->token())
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }
}
