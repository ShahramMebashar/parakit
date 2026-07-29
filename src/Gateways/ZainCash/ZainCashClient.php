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
        return $this->request('POST', '/api/v2/payment-gateway/transaction/init', $payload);
    }

    /** @return array<string, mixed> */
    public function inquiry(string $transactionId): array
    {
        return $this->request(
            'GET',
            '/api/v2/payment-gateway/transaction/inquiry/' . rawurlencode($transactionId),
        );
    }

    /** @return array<string, mixed> */
    public function reverse(string $transactionId, string $reason): array
    {
        return $this->request('POST', '/api/v2/payment-gateway/transaction/reverse', [
            'transactionId' => $transactionId,
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    private function request(string $verb, string $uri, ?array $payload = null): array
    {
        $response = $this->send($verb, $uri, $payload);

        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->send($verb, $uri, $payload);
        }

        $status = $response->status();
        if ($status >= 500) {
            throw new GatewayUnavailableException("ZainCash {$uri} failed: HTTP {$status}");
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if ($status >= 400) {
            $code = (string) ($json['code'] ?? $json['errorCode'] ?? data_get($json, 'error.code', ''));
            $message = (string) ($json['message'] ?? data_get($json, 'error.message', "HTTP {$status}"));
            throw new ZainCashApiException(
                "ZainCash {$uri} rejected: {$message}",
                $status,
                $code,
                $json,
            );
        }

        return $json;
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
