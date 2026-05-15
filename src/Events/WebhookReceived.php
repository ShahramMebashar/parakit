<?php
declare(strict_types=1);

namespace Froshly\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Froshly\Parakit\DTOs\WebhookPayload;

class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookPayload $payload) {}
}
