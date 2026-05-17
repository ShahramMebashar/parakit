<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\NassWallet;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\PaymentException;

/**
 * HTTP transport for the NassWallet payment gateway.
 *
 * Every request body is wrapped as `{"data": {...}}` and authenticated with a
 * Bearer token from NassWalletTokenCache. Success is signalled by the body
 * `responseCode` (0 = ok); `errCode` is "1" even on success and is not used.
 */
final class NassWalletClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly NassWalletTokenCache $tokens,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function initTransaction(array $data): array
    {
        return $this->send('/initTransaction', $data);
    }

    /** @return array<string, mixed> */
    public function checkTransaction(string $transactionId): array
    {
        return $this->send('/checkTransaction', ['transactionId' => $transactionId]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function send(string $uri, array $data): array
    {
        $response = $this->dispatch($uri, $data, $this->tokens->token());

        // A 401 means the cached token is stale — drop it, re-login, retry once.
        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $this->dispatch($uri, $data, $this->tokens->token());
        }

        // 5xx is transient — retryable by AbstractGateway's retry loop.
        if ($response->status() >= 500) {
            throw new GatewayUnavailableException("NassWallet {$uri} failed: HTTP {$response->status()}");
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // 4xx and an explicit non-zero responseCode are deterministic — throw a
        // non-retryable PaymentException so the retry loop never re-issues a
        // request that will fail identically.
        if (!$response->successful() || ($json['responseCode'] ?? null) !== 0) {
            $message = is_string($json['message'] ?? null)
                ? $json['message']
                : "HTTP {$response->status()}";
            throw new PaymentException("NassWallet {$uri} rejected: {$message}");
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatch(string $uri, array $data, string $token): Response
    {
        try {
            return Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken($token)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($uri, ['data' => $data]);
        } catch (ConnectionException $e) {
            // Network/timeout failure — retryable.
            throw new GatewayUnavailableException(
                "NassWallet {$uri} unreachable: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
}
