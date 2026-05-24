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
        public ?string $correlationId = null,
    ) {}

    /** Returns a copy tagged with the given correlation id; the original is untouched. */
    public function withCorrelationId(string $correlationId): self
    {
        return new self(
            gateway: $this->gateway,
            gatewayTransactionId: $this->gatewayTransactionId,
            reference: $this->reference,
            status: $this->status,
            amount: $this->amount,
            currency: $this->currency,
            eventId: $this->eventId,
            occurredAt: $this->occurredAt,
            error: $this->error,
            raw: $this->raw,
            correlationId: $correlationId,
        );
    }
}
