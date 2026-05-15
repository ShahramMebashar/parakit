<?php
declare(strict_types=1);

namespace Shah\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Shah\Parakit\DTOs\WebhookPayload;

class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookPayload $payload) {}
}
