<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\QiCard;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayTimeoutException;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

/**
 * HTTP transport for the QiCard payment gateway.
 *
 * Every request is sent with Basic Auth (username + password) and the
 * `X-Terminal-Id` header. 5xx errors are retryable (GatewayUnavailableException);
 * a 4xx with QiCard's `{error:{code,message|description}}` body is
 * deterministic and surfaces as QiCardApiException so AbstractGateway's retry
 * loop short-circuits.
 */
final class QiCardClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $terminalId,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function createPayment(array $body): array
    {
        return $this->postJson('/api/v1/payment', $body);
    }

    /** @return array<string, mixed> */
    public function getStatus(string $paymentId): array
    {
        return $this->getJson('/api/v1/payment/' . rawurlencode($paymentId) . '/status');
    }

    /** @return array<string, mixed> */
    public function getStatusByRequestId(string $requestId): array
    {
        return $this->getJson('/api/v1/payment/status/by/request/' . rawurlencode($requestId));
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function cancel(string $paymentId, array $body): array
    {
        return $this->postJson('/api/v1/payment/' . rawurlencode($paymentId) . '/cancel', $body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function refund(string $paymentId, array $body): array
    {
        return $this->postJson('/api/v1/payment/' . rawurlencode($paymentId) . '/refund', $body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function postJson(string $uri, array $body): array
    {
        return $this->parse($uri, $this->dispatch(fn (PendingRequest $r) => $r->post($uri, $body), $uri));
    }

    /** @return array<string, mixed> */
    private function getJson(string $uri): array
    {
        return $this->parse($uri, $this->dispatch(fn (PendingRequest $r) => $r->get($uri), $uri));
    }

    /**
     * @param \Closure(PendingRequest): Response $send
     */
    private function dispatch(\Closure $send, string $uri): Response
    {
        $start = hrtime(true);

        $client = Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withBasicAuth($this->username, $this->password)
            ->withHeaders(['X-Terminal-Id' => $this->terminalId])
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();

        try {
            return $send($client);
        } catch (ConnectionException $e) {
            // Network/timeout failure — retryable, and surfaced as a timeout
            // so AbstractGateway can dispatch a GatewayTimeout event.
            throw new GatewayTimeoutException(
                $uri,
                (int) ((hrtime(true) - $start) / 1_000_000),
                "QiCard {$uri} timed out: {$e->getMessage()}",
                $e,
            );
        }
    }

    /** @return array<string, mixed> */
    private function parse(string $uri, Response $response): array
    {
        $status = $response->status();

        if ($status >= 500) {
            throw new GatewayUnavailableException("QiCard {$uri} failed: HTTP {$status}");
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($status >= 400) {
            // QiCard's prose docs show `{error:{code,description}}` while the
            // OpenAPI spec types it as `{error:{code,message}}`. Accept both
            // to stay safe whichever shape the live API actually returns.
            $err = is_array($json['error'] ?? null) ? $json['error'] : [];
            $code = (int) ($err['code'] ?? 0);
            $message = (string) ($err['message'] ?? $err['description'] ?? "HTTP {$status}");
            throw new QiCardApiException("QiCard {$uri} rejected: {$message}", $code);
        }

        return $json;
    }
}
