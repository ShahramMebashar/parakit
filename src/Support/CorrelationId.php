<?php
declare(strict_types=1);

namespace Gutian\Parakit\Support;

use Illuminate\Support\Str;

final class CorrelationId
{
    public const CONTEXT_KEY = 'parakit.correlation_id';

    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    public static function current(): string
    {
        if (app()->bound(self::CONTEXT_KEY)) {
            return (string) app(self::CONTEXT_KEY);
        }

        $id = self::generate();
        app()->instance(self::CONTEXT_KEY, $id);
        return $id;
    }

    public static function reset(): void
    {
        if (app()->bound(self::CONTEXT_KEY)) {
            app()->forgetInstance(self::CONTEXT_KEY);
        }
    }
}
