<?php
declare(strict_types=1);

namespace Froshly\Parakit\Exceptions;

use Throwable;

/**
 * A gateway call did not answer in time (a connection timeout or other
 * network failure).
 *
 * Extends {@see GatewayUnavailableException} on purpose: every existing
 * retry and circuit-breaker code path already treats that type as transient,
 * so a timeout stays retryable with no extra handling. The subtype only adds
 * the endpoint and elapsed time, so {@see \Froshly\Parakit\Gateways\AbstractGateway}
 * can dispatch a {@see \Froshly\Parakit\Events\GatewayTimeout} event.
 */
class GatewayTimeoutException extends GatewayUnavailableException
{
    public function __construct(
        public readonly string $endpoint,
        public readonly int $durationMs,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
