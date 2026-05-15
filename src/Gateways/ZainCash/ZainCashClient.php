<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

/**
 * Bearer-authenticated HTTP client for the ZainCash v2 Payment Gateway.
 *
 * All requests and responses are JSON; the OAuth2 access token is supplied by
 * ZainCashTokenCache. Non-2xx responses are surfaced as GatewayUnavailable
 * so AbstractGateway's retry/circuit-breaker logic engages.
 */
final class ZainCashClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ZainCashTokenCache $tokens,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function init(array $payload): array
    {
        $res = $this->client()->post('/api/v2/payment-gateway/transaction/init', $payload);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash init failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function inquiry(string $transactionId): array
    {
        $res = $this->client()->get("/api/v2/payment-gateway/transaction/inquiry/{$transactionId}");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash inquiry failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function reverse(string $transactionId, string $reason): array
    {
        $res = $this->client()->post('/api/v2/payment-gateway/transaction/reverse', [
            'transactionId' => $transactionId,
            'reason' => $reason,
        ]);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash reverse failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->tokens->token())
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }
}
