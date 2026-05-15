<?php
declare(strict_types=1);

namespace Gutian\Parakit\Enums;

enum Currency: string
{
    case IQD = 'IQD';
    case USD = 'USD';

    public function minorUnitFactor(): int
    {
        return match ($this) {
            self::IQD => 1,
            self::USD => 100,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::IQD => 'IQD',
            self::USD => '$',
        };
    }
}
