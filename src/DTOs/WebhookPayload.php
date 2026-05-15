<?php
declare(strict_types=1);

namespace Gutian\Parakit\DTOs;

use DateTimeImmutable;
use Gutian\Parakit\Enums\Currency;
use Gutian\Parakit\Enums\PaymentStatus;

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
