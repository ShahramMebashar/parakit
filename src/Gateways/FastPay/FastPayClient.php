<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\FastPay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

/**
 * HTTP transport for the FastPay payment gateway.
 *
 * FastPay has no OAuth/bearer token — every request authenticates with
 * store_id + store_password in the JSON body, which FastPayGateway injects.
 * It also returns HTTP 200 for both success and failure, so the real outcome
 * is the body `code` field (200 = ok, 422/404 = error).
 */
final class FastPayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function initiation(array $body): array
    {
        return $this->send('/api/v1/public/pgw/payment/initiation', $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function validate(array $body): array
    {
        return $this->send('/api/v1/public/pgw/payment/validate', $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function refund(array $body): array
    {
        return $this->send('/api/v1/public/pgw/payment/refund', $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function send(string $uri, array $body): array
    {
        $response = $this->dispatch($uri, $body);

        // 5xx is transient — retryable by AbstractGateway's retry loop.
        if ($response->status() >= 500) {
            throw new GatewayUnavailableException("FastPay {$uri} failed: HTTP {$response->status()}");
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // FastPay answers HTTP 200 even for errors; the body `code` is the
        // real outcome. Anything other than 200 (422 bad creds, 404 not
        // found) is deterministic — throw a non-retryable FastPayApiException
        // so the retry loop never re-sends a request that will fail
        // identically. The `code` is carried so callers can tell 404 apart.
        $code = $json['code'] ?? null;
        if ($code !== 200) {
            throw new FastPayApiException(
                "FastPay {$uri} rejected: " . $this->firstMessage($json),
                is_int($code) ? $code : 0,
            );
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(string $uri, array $body): Response
    {
        try {
            return Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($uri, $body);
        } catch (ConnectionException $e) {
            // Network/timeout failure — retryable.
            throw new GatewayUnavailableException(
                "FastPay {$uri} unreachable: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /** @param array<string, mixed> $json */
    private function firstMessage(array $json): string
    {
        $messages = $json['messages'] ?? null;

        if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
            return $messages[0];
        }

        return 'HTTP ' . (string) ($json['code'] ?? 'unknown');
    }
}
