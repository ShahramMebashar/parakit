<?php
declare(strict_types=1);

use Gutian\Parakit\Support\Money;
use Gutian\Parakit\Enums\Currency;

it('formats minor units back to a decimal string per currency', function () {
    expect(Money::format(5000, Currency::IQD))->toBe('5000')
        ->and(Money::format(1234, Currency::USD))->toBe('12.34');
});

it('parses decimal strings into minor units', function () {
    expect(Money::parse('12.34', Currency::USD))->toBe(1234)
        ->and(Money::parse('5000', Currency::IQD))->toBe(5000);
});

it('rounds half-up rather than truncating extra fractional digits', function () {
    expect(Money::parse('1.235', Currency::USD))->toBe(124)
        ->and(Money::parse('1.234', Currency::USD))->toBe(123);
});

it('rejects negative, non-numeric, multi-decimal, or empty input', function () {
    expect(fn () => Money::parse('-5.00', Currency::USD))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('abc', Currency::USD))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('1.2.3', Currency::USD))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('', Currency::USD))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('1,50', Currency::USD))->toThrow(InvalidArgumentException::class);
});
