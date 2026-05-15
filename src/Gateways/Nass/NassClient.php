<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Nass;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\PaymentException;

final class NassClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly NassTokenCache $tokens,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function initTransaction(array $payload): array
    {
        return $this->send('POST', '/transaction', $payload);
    }

    /** @return array<string, mixed> */
    public function checkStatus(string $orderId): array
    {
        return $this->send('GET', "/transaction/{$orderId}/checkStatus", null);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, ?array $body): array
    {
        $response = $this->dispatch($method, $uri, $body, $this->tokens->token());

        // A 401 means the cached token is stale — drop it, re-login, retry once.
        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->dispatch($method, $uri, $body, $this->tokens->token());
        }

        // 5xx is transient — retryable by AbstractGateway's retry loop.
        if ($response->status() >= 500) {
            throw new GatewayUnavailableException("NassPay {$uri} failed: HTTP {$response->status()}");
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // 4xx (incl. 409 "Order ID already exists") and an explicit
        // success:false are deterministic — throw a non-retryable
        // PaymentException so the retry loop never double-creates a txn.
        if (!$response->successful() || ($json['success'] ?? true) === false) {
            $message = $json['data']['message'] ?? "HTTP {$response->status()}";
            throw new PaymentException("NassPay {$uri} rejected: {$message}");
        }

        return $json;
    }

    /** @param array<string, mixed>|null $body */
    private function dispatch(string $method, string $uri, ?array $body, string $token): Response
    {
        try {
            $request = Http::baseUrl($this->baseUrl)
                ->withToken($token)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson();

            return $method === 'GET'
                ? $request->get($uri)
                : $request->post($uri, $body ?? []);
        } catch (ConnectionException $e) {
            // Network/timeout failure — retryable.
            throw new GatewayUnavailableException(
                "NassPay {$uri} unreachable: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
}
