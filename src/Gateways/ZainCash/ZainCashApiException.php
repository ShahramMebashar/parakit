<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Froshly\Parakit\Exceptions\PaymentException;

final class ZainCashApiException extends PaymentException
{
    /** @param array<string, mixed> $response */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $apiCode = '',
        public readonly array $response = [],
    ) {
        parent::__construct($message);
    }
}
