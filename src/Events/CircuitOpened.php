<?php
declare(strict_types=1);

namespace Gutian\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CircuitOpened
{
    use Dispatchable;

    public function __construct(public readonly string $gateway) {}
}
