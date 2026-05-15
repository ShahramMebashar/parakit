<?php
declare(strict_types=1);

namespace Shah\Parakit\DTOs;

use DateTimeImmutable;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Enums\PaymentStatus;

final readonly class PaymentResponse
{
    public function __construct(
        public bool $success,
        public string $gateway,
        public ?string $gatewayTransactionId,
        public string $reference,
        public PaymentStatus $status,
        public int $amount,
        public Currency $currency,
        public string $correlationId,
        public ?string $redirectUrl = null,
        public ?string $qrCode = null,
        public ?string $deepLink = null,
        public ?string $readableCode = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?PaymentError $error = null,
        public array $raw = [],
    ) {}

    public function failed(): bool
    {
        return !$this->success;
    }
}
