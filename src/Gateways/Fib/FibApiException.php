<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Fib;

use Froshly\Parakit\Exceptions\PaymentException;

/**
 * A deterministic FIB rejection — the request reached FIB and was declined
 * with a 4xx (bad credentials, bad request, conflict). Non-retryable, so
 * AbstractGateway's retry loop short-circuits instead of burning attempts
 * on a request FIB will deterministically reject. Carries the HTTP status
 * for callers that need to distinguish 401 from 409.
 */
final class FibApiException extends PaymentException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
