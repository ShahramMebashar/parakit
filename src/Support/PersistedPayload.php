<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

final class PersistedPayload
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function prepare(array $payload): array
    {
        if ($payload === [] || ! config('parakit.raw_payloads.store', true)) {
            return [];
        }

        if (! config('parakit.raw_payloads.redact', true)) {
            return $payload;
        }

        return (new PayloadRedactor(
            (array) config('parakit.logging.redact_keys', []),
        ))->redact($payload);
    }
}
