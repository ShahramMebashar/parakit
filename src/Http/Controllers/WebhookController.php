<?php
declare(strict_types=1);

namespace Shah\Parakit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Shah\Parakit\Events\WebhookReceived;
use Shah\Parakit\Events\WebhookVerificationFailed;
use Shah\Parakit\Exceptions\DuplicateWebhookException;
use Shah\Parakit\Exceptions\InvalidWebhookSignatureException;
use Shah\Parakit\Exceptions\UnsupportedGatewayException;
use Shah\Parakit\PaymentManager;
use Shah\Parakit\Support\WebhookProcessor;

class WebhookController
{
    /**
     * Headers that must NEVER appear in the WebhookVerificationFailed event:
     * propagating them through listeners (Telescope, broadcasts, log channels)
     * would leak the merchant's credentials.
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'proxy-authorization',
        'x-api-key',
    ];

    public function __invoke(
        Request $request,
        PaymentManager $manager,
        WebhookProcessor $processor,
        string $gateway,
    ): Response {
        try {
            $driver = $manager->driver($gateway);
        } catch (UnsupportedGatewayException) {
            return new Response('Unknown gateway', 404);
        }

        try {
            $payload = $driver->handleWebhook($request);
        } catch (InvalidWebhookSignatureException $e) {
            event(new WebhookVerificationFailed(
                gateway: $gateway,
                reason: $e->getMessage(),
                headers: $this->safeHeaders($request),
            ));
            return new Response('invalid signature', 401);
        } catch (\Throwable $e) {
            Log::error('parakit.webhook.driver_exception', [
                'gateway' => $gateway,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return new Response('driver error', 500);
        }

        $tolerance = (int) config('parakit.webhooks.tolerance_seconds', 300);
        if ($processor->isReplay($payload, $tolerance)) {
            return new Response('stale', 400);
        }

        event(new WebhookReceived($payload));

        try {
            $eventRow = $processor->recordEvent($payload);
        } catch (DuplicateWebhookException) {
            return new Response('duplicate', 200);
        }

        $processor->applyToTransaction($payload, $eventRow);

        return new Response('ok', 200);
    }

    /**
     * @return array<string, string[]>
     */
    private function safeHeaders(Request $request): array
    {
        $headers = $request->headers->all();
        foreach ($headers as $name => $_) {
            if (in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
                $headers[$name] = ['[REDACTED]'];
            }
        }
        return $headers;
    }
}
