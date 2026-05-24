<?php
declare(strict_types=1);

namespace Froshly\Parakit\Events;

/**
 * Implemented by every webhook lifecycle event so a single listener can
 * observe all webhook activity: `Event::listen(WebhookEvent::class, ...)`.
 * Laravel fires interface listeners for any event implementing the interface.
 */
interface WebhookEvent
{
    public function gateway(): string;
}
