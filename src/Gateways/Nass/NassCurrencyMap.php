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

    /** ISO 4217 numeric code => Currency enum value (inverse of TO_CODE). */
    private const FROM_CODE = [
        '368' => 'IQD',
        '840' => 'USD',
    ];

    public static function toCode(Currency $currency): string
    {
        // Every Currency enum case has an entry in TO_CODE.
        return self::TO_CODE[$currency->value];
    }

    public static function fromCode(string $code): ?Currency
    {
        return isset(self::FROM_CODE[$code])
            ? Currency::from(self::FROM_CODE[$code])
            : null;
    }
}
