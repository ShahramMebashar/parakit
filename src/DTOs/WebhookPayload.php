<?php
declare(strict_types=1);

namespace Froshly\Parakit\DTOs;

use DateTimeImmutable;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;

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
