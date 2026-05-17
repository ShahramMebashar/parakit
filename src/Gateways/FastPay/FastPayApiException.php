<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\FastPay;

use Froshly\Parakit\Exceptions\PaymentException;

/**
 * A deterministic FastPay rejection — the request reached FastPay and was
 * declined with a body `code` other than 200 (e.g. 422 bad credentials,
 * 404 order not found).
 *
 * Extends PaymentException so it stays non-retryable in AbstractGateway's
 * retry loop, while also carrying the FastPay `code` so callers can tell a
 * "not found" (404) apart from a real failure.
 */
final class FastPayApiException extends PaymentException
{
    public function __construct(
        string $message,
        public readonly int $apiCode,
    ) {
        parent::__construct($message);
    }
}
