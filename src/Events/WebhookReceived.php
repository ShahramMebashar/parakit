<?php
declare(strict_types=1);

namespace Froshly\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Froshly\Parakit\DTOs\WebhookPayload;

class WebhookReceived implements WebhookEvent
{
    use Dispatchable;

    public function __construct(public readonly WebhookPayload $payload) {}

    public function gateway(): string
    {
        return $this->payload->gateway;
    }
}
