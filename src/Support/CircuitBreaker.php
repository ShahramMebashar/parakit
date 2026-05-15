<?php
declare(strict_types=1);

namespace Gutian\Parakit\Support;

use Illuminate\Support\Facades\Cache;

final class CircuitBreaker
{
    public function __construct(
        private readonly string $gateway,
        private readonly int $threshold,
        private readonly int $cooldownSeconds,
    ) {}

    public function isOpen(): bool
    {
        $openedAt = Cache::get($this->openedKey());
        if ($openedAt === null) {
            return false;
        }
        if (time() - (int) $openedAt >= $this->cooldownSeconds) {
            Cache::forget($this->openedKey());
            Cache::forget($this->failsKey());
            return false;
        }
        return true;
    }

    public function recordFailure(): void
    {
        // Seed the fail counter so the first failure is never lost on stores
        // (Redis, array driver) where increment() returns false for missing keys.
        // Also bounds the key's lifetime so failure counts age out.
        Cache::add($this->failsKey(), 0, $this->cooldownSeconds + 300);
        $fails = (int) Cache::increment($this->failsKey());
        if ($fails >= $this->threshold) {
            Cache::put($this->openedKey(), time(), $this->cooldownSeconds + 60);
        }
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failsKey());
        Cache::forget($this->openedKey());
    }

    private function failsKey(): string  { return "parakit:cb:{$this->gateway}:fails"; }
    private function openedKey(): string { return "parakit:cb:{$this->gateway}:opened_at"; }
}
