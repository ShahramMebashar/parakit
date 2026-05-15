<?php
declare(strict_types=1);

namespace Gutian\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Gutian\Parakit\DTOs\WebhookPayload;

class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly WebhookPayload $payload) {}
}
