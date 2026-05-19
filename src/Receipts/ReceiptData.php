<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

use DateTimeImmutable;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;

/**
 * Immutable view-model handed to a receipt Blade template.
 *
 * Carries no config or Eloquent coupling — everything a template needs is a
 * plain scalar, so the same data renders through any template unchanged.
 */
final readonly class ReceiptData
{
    public function __construct(
        public ReceiptType $type,
        public string $transactionId,
        public string $gateway,
        public string $reference,
        public ?string $gatewayTransactionId,
        public PaymentStatus $status,
        public Currency $currency,
        public int $amountMinor,
        public string $amountFormatted,
        public int $refundedMinor,
        public string $refundedFormatted,
        public bool $isPartialRefund,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public DateTimeImmutable $issuedAt,
        public ?DateTimeImmutable $paidAt,
        public string $locale,
        /** @var array<string,mixed> */
        public array $merchant,
        /** @var array<string,mixed> */
        public array $metadata,
    ) {}

    public function currencySymbol(): string
    {
        return $this->currency->symbol();
    }

    /** True for right-to-left locales (Arabic, Kurdish Sorani). */
    public function isRtl(): bool
    {
        return in_array($this->locale, ['ar', 'ckb'], true);
    }

    public function isRefund(): bool
    {
        return $this->type === ReceiptType::Refund;
    }

    public function hasCustomer(): bool
    {
        return $this->customerName !== null
            || $this->customerEmail !== null
            || $this->customerPhone !== null;
    }
}
