<?php
declare(strict_types=1);

namespace Froshly\Parakit\DTOs;

use InvalidArgumentException;
use Froshly\Parakit\Enums\Currency;

final readonly class PaymentRequest
{
    public function __construct(
        public string $reference,
        public int $amount,
        public Currency $currency,
        public string $description,
        public ?string $customerPhone = null,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public ?string $callbackUrl = null,
        public ?string $returnUrl = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {
        if ($this->amount <= 0) {
            throw new InvalidArgumentException('amount must be a positive integer in minor units');
        }
        if ($this->reference === '') {
            throw new InvalidArgumentException('reference must not be empty');
        }
    }
}
