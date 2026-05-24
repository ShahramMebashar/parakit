<?php
declare(strict_types=1);

namespace Froshly\Parakit\Enums;

/**
 * What to do when a `Paid` webhook reports an amount that differs from the
 * charged amount. Backs the `parakit.webhooks.on_amount_mismatch` config key.
 */
enum AmountMismatchPolicy: string
{
    /** Record the mismatch and apply the webhook anyway. */
    case Log = 'log';

    /** Record the mismatch and refuse to settle the transaction. */
    case Reject = 'reject';

    /** Resolves a config value, falling back to Log for unknown or non-string input. */
    public static function fromConfig(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Log) : self::Log;
    }
}
