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
            // FIB's create-payment field is `statusCallbackUrl` — the webhook
            // URL FIB POSTs to on status changes.
            'statusCallbackUrl' => (string) ($params['callback'] ?? ''),
        ];
        // Optional create-payment fields, sent only when supplied.
        foreach (['redirectUri', 'expiresIn', 'refundableFor', 'category'] as $opt) {
            if (isset($params[$opt]) && $params[$opt] !== '') {
                $payload[$opt] = $params[$opt];
            }
        }

        $res = $this->send('POST', '/protected/v1/payments', $payload);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB charge failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function fetchStatus(string $paymentId): array
    {
        $res = $this->send('GET', "/protected/v1/payments/{$paymentId}/status");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB status failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function refund(string $paymentId): array
    {
        $res = $this->send('POST', "/protected/v1/payments/{$paymentId}/refund");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB refund failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function cancel(string $paymentId): array
    {
        $res = $this->send('POST', "/protected/v1/payments/{$paymentId}/cancel");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("FIB cancel failed: HTTP {$res->status()}");
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
