<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Nass;

use Froshly\Parakit\Enums\Currency;

final class NassCurrencyMap
{
    /** Currency enum value => ISO 4217 numeric code NassPay expects. */
    private const TO_CODE = [
        'IQD' => '368',
        'USD' => '840',
    ];

    public static function toCode(Currency $currency): string
    {
        return self::TO_CODE[$currency->value] ?? '368';
    }

    public static function fromCode(string $code): ?Currency
    {
        $flipped = array_flip(self::TO_CODE);
        return isset($flipped[$code]) ? Currency::from($flipped[$code]) : null;
    }
}
