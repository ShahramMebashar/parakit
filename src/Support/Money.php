<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

use InvalidArgumentException;
use Froshly\Parakit\Enums\Currency;

final class Money
{
    public static function format(int $minor, Currency $c): string
    {
        $factor = $c->minorUnitFactor();
        if ($factor === 1) {
            return (string) $minor;
        }
        $decimals = (int) log10($factor);
        $major = intdiv($minor, $factor);
        $rem = $minor % $factor;
        return sprintf('%d.%0' . $decimals . 'd', $major, $rem);
    }

    public static function parse(string $value, Currency $c): int
    {
        if ($value === '' || !preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Money::parse expects a non-negative decimal string, got: {$value}");
        }

        $factor = $c->minorUnitFactor();
        if ($factor === 1) {
            return (int) $value;
        }

        return (int) round(((float) $value) * $factor);
    }
}
