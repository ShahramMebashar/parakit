<?php
declare(strict_types=1);

namespace Froshly\Parakit\DTOs;

final readonly class RefundResponse
{
    public function __construct(
        public bool $success,
        public ?string $refundId,
        public int $refundedAmount,
        public ?PaymentError $error = null,
        public array $raw = [],
    ) {}
}
