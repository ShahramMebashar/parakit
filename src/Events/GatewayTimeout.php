<?php
declare(strict_types=1);

namespace Gutian\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;

class GatewayTimeout
{
    use Dispatchable;

    public function __construct(
        public readonly string $gateway,
        public readonly string $endpoint,
        public readonly int $durationMs,
    ) {}
}
