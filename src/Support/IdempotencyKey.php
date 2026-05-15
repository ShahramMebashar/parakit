<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

final class IdempotencyKey
{
    public static function derive(string $gateway, string $reference, int $amount, string $currency): string
    {
        return hash('sha256', implode('|', [$gateway, $reference, (string) $amount, $currency]));
    }
}
