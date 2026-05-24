<?php
declare(strict_types=1);

use Froshly\Parakit\Enums\AmountMismatchPolicy;

it('parses valid config strings', function () {
    expect(AmountMismatchPolicy::fromConfig('log'))->toBe(AmountMismatchPolicy::Log)
        ->and(AmountMismatchPolicy::fromConfig('reject'))->toBe(AmountMismatchPolicy::Reject);
});

it('falls back to Log for unknown or non-string values', function () {
    expect(AmountMismatchPolicy::fromConfig('rejcet'))->toBe(AmountMismatchPolicy::Log)
        ->and(AmountMismatchPolicy::fromConfig(null))->toBe(AmountMismatchPolicy::Log)
        ->and(AmountMismatchPolicy::fromConfig(['reject']))->toBe(AmountMismatchPolicy::Log);
});
