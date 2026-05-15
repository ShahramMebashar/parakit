<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\Nass\NassCurrencyMap;
use Froshly\Parakit\Enums\Currency;

it('maps Currency enum to NassPay ISO numeric codes', function () {
    expect(NassCurrencyMap::toCode(Currency::IQD))->toBe('368')
        ->and(NassCurrencyMap::toCode(Currency::USD))->toBe('840');
});

it('maps NassPay ISO numeric codes back to Currency', function () {
    expect(NassCurrencyMap::fromCode('368'))->toBe(Currency::IQD)
        ->and(NassCurrencyMap::fromCode('840'))->toBe(Currency::USD);
});

it('returns null for an unknown numeric currency code', function () {
    expect(NassCurrencyMap::fromCode('999'))->toBeNull();
});
