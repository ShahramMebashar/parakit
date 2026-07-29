<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Fib;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayTimeoutException;
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
            // FIB caps the description at 50 characters and rejects the whole
            // request if it overflows — truncate rather than fail the charge.
            'description' => mb_substr((string) ($params['description'] ?? ''), 0, 50),
        ];
        $callback = (string) ($params['callback'] ?? '');
        if ($callback !== '') {
            $payload['statusCallbackUrl'] = $callback;
        }
        // Optional create-payment fields, sent only when supplied.
        foreach (['redirectUri', 'expiresIn', 'refundableFor', 'category'] as $opt) {
            if (isset($params[$opt]) && $params[$opt] !== '') {
                $payload[$opt] = $params[$opt];
            }
        }

        return $this->parse('charge', '/protected/v1/payments', $this->send('POST', '/protected/v1/payments', $payload));
    }

    /** @return array<string, mixed> */
    public function fetchStatus(string $paymentId): array
    {
        $uri = '/protected/v1/payments/' . rawurlencode($paymentId) . '/status';
        return $this->parse('status', $uri, $this->send('GET', $uri));
    }

    /** @return array<string, mixed> */
    public function refund(string $paymentId): array
    {
        $uri = '/protected/v1/payments/' . rawurlencode($paymentId) . '/refund';
        return $this->parse('refund', $uri, $this->send('POST', $uri));
    }

    /** @return array<string, mixed> */
    public function cancel(string $paymentId): array
    {
        $uri = '/protected/v1/payments/' . rawurlencode($paymentId) . '/cancel';
        return $this->parse('cancel', $uri, $this->send('POST', $uri));
    }

    /**
     * Discriminate retryable 5xx from deterministic 4xx. 4xx is a config /
     * state problem (bad credentials, already paid, expired); retrying it
     * just burns circuit-breaker ticks against an outage parakit doesn't have.
     *
     * @return array<string, mixed>
     */
    private function parse(string $op, string $uri, Response $res): array
    {
        $status = $res->status();
        if ($status >= 500) {
            throw new GatewayUnavailableException("FIB {$op} failed: HTTP {$status}");
        }
        if ($status >= 400) {
            throw new FibApiException("FIB {$op} rejected: HTTP {$status}", $status);
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /**
     * Single HTTP chokepoint. A connection timeout (here or while fetching the
     * bearer token) is surfaced as a GatewayTimeoutException so the retry loop
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
                "FIB {$uri} timed out: {$e->getMessage()}",
                $e,
            );
        }
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
