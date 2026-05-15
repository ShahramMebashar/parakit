<?php
declare(strict_types=1);

use Shah\Parakit\Enums\Currency;

it('returns minor-unit factor per currency', function () {
    expect(Currency::IQD->minorUnitFactor())->toBe(1)
        ->and(Currency::USD->minorUnitFactor())->toBe(100);
});

it('returns a symbol per currency', function () {
    expect(Currency::IQD->symbol())->toBe('IQD')
        ->and(Currency::USD->symbol())->toBe('$');
});
