<?php
declare(strict_types=1);

namespace Froshly\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;

class WebhookVerificationFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $gateway,
        public readonly string $reason,
        public readonly array $headers = [],
    ) {}
}
