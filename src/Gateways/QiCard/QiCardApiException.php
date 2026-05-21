<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\QiCard;

use Froshly\Parakit\Exceptions\PaymentException;

/**
 * A deterministic QiCard rejection — the request reached QiCard and was
 * declined with a 4xx and a body `{error: {code, message}}`. The numeric
 * `apiCode` (1–35) is carried so callers can distinguish e.g. `PAYMENT_NOT_FOUND`
 * (12) from `BAD_CREDENTIALS` (27) without re-parsing the message.
 *
 * Extends PaymentException so it stays non-retryable in AbstractGateway's
 * retry loop.
 */
final class QiCardApiException extends PaymentException
{
    public function __construct(
        string $message,
        public readonly int $apiCode,
    ) {
        parent::__construct($message);
    }
}
