<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\NassWallet;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayTimeoutException;
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
        $responseCode = $json['responseCode'] ?? null;
        $providerSucceeded = is_numeric($responseCode) && (int) $responseCode === 0;
        if (!$response->successful() || ! $providerSucceeded) {
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
        $start = hrtime(true);
        try {
            return Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken($token)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($uri, ['data' => $data]);
        } catch (ConnectionException $e) {
            // Network/timeout failure — retryable, and surfaced as a timeout
            // so AbstractGateway can dispatch a GatewayTimeout event.
            throw new GatewayTimeoutException(
                $uri,
                (int) ((hrtime(true) - $start) / 1_000_000),
                "NassWallet {$uri} timed out: {$e->getMessage()}",
                $e,
            );
        }
    }
}
