<?php
declare(strict_types=1);

namespace Shah\Parakit\DTOs;

use DateTimeImmutable;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Enums\PaymentStatus;

final readonly class WebhookPayload
{
    public function __construct(
        public string $gateway,
        public string $gatewayTransactionId,
        public string $reference,
        public PaymentStatus $status,
        public int $amount,
        public Currency $currency,
        public string $eventId,
        public DateTimeImmutable $occurredAt,
        public ?PaymentError $error = null,
        public array $raw = [],
    ) {}
}
