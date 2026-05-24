<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DomainEventDispatcher
{
    public static function afterCommit(object $event): void
    {
        DB::afterCommit(function () use ($event) {
            try {
                event($event);
            } catch (\Throwable $e) {
                Log::error('parakit.domain_event_listener_failed', [
                    'event' => $event::class,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
