<?php
declare(strict_types=1);

namespace Gutian\Parakit\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Expired = 'expired';
    case Disputed = 'disputed';

    private const TRANSITIONS = [
        'pending'            => ['processing', 'paid', 'failed', 'cancelled', 'expired'],
        'processing'         => ['paid', 'failed', 'cancelled', 'expired'],
        'paid'               => ['refunded', 'partially_refunded', 'disputed'],
        'partially_refunded' => ['refunded', 'disputed'],
        'refunded'           => ['disputed'],
        'failed'             => [],
        'cancelled'          => [],
        'expired'            => [],
        'disputed'           => ['refunded', 'partially_refunded'],
    ];

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Paid, self::Failed, self::Cancelled,
            self::Refunded, self::Expired,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this, [
            self::Paid, self::PartiallyRefunded, self::Refunded,
        ], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }

        return in_array($next->value, self::TRANSITIONS[$this->value], true);
    }
}
