<?php
declare(strict_types=1);

namespace Shah\Parakit\DTOs;

use InvalidArgumentException;

final readonly class RefundRequest
{
    public function __construct(
        public string $transactionId,
        public int $amount,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if ($this->amount <= 0) {
            throw new InvalidArgumentException('refund amount must be positive minor units');
        }
    }
}
