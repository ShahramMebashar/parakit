<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayTimeoutException;
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
        $res = $this->send('POST', '/api/v2/payment-gateway/transaction/init', $payload);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash init failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function inquiry(string $transactionId): array
    {
        $res = $this->send('GET', "/api/v2/payment-gateway/transaction/inquiry/{$transactionId}");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash inquiry failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function reverse(string $transactionId, string $reason): array
    {
        $res = $this->send('POST', '/api/v2/payment-gateway/transaction/reverse', [
            'transactionId' => $transactionId,
            'reason' => $reason,
        ]);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash reverse failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /**
     * Single HTTP chokepoint. A connection timeout (here or while fetching the
     * OAuth2 token) is surfaced as a GatewayTimeoutException so the retry loop
     * stays engaged and AbstractGateway can dispatch a GatewayTimeout event.
     *
     * @param array<string, mixed>|null $payload
     */
    private function send(string $verb, string $uri, ?array $payload = null): Response
    {
        $start = hrtime(true);
        try {
            $request = $this->client();

            return $verb === 'GET'
                ? $request->get($uri)
                : $request->post($uri, $payload ?? []);
        } catch (ConnectionException $e) {
            throw new GatewayTimeoutException(
                $uri,
                (int) ((hrtime(true) - $start) / 1_000_000),
                "ZainCash {$uri} timed out: {$e->getMessage()}",
                $e,
            );
        }
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
