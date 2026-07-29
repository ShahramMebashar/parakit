<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\Nass;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayTimeoutException;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\PaymentException;

final class NassTokenCache
{
    private const SAFETY_MARGIN = 60;

    private readonly string $cacheKey;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly int $ttl = 3000,
    ) {
        // Scope the cache key to base URL + username so two NassPay configs
        // never share a token. xxh3 is fast and non-cryptographic — uniqueness
        // is all that is needed here.
        $this->cacheKey = 'parakit:nass:token:' . hash('xxh3', $this->baseUrl . '|' . $this->username);
    }

    public function token(): string
    {
        $cached = Cache::get($this->cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        return $this->login();
    }

    /** Drop the cached token — used by NassClient after a 401. */
    public function forget(): void
    {
        Cache::forget($this->cacheKey);
    }

    private function login(): string
    {
        $uri = '/auth/merchant/login';
        $start = hrtime(true);
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('parakit.reliability.timeout_seconds', 15))
                ->post($this->baseUrl . $uri, [
                    'username' => $this->username,
                    'password' => $this->password,
                ]);
        } catch (ConnectionException $e) {
            throw new GatewayTimeoutException(
                $uri,
                (int) ((hrtime(true) - $start) / 1_000_000),
                "NassPay login timed out: {$e->getMessage()}",
                $e,
            );
        }

        // 5xx is transient — let the caller's retry layer handle it.
        if ($response->status() >= 500) {
            throw new GatewayUnavailableException(
                "NassPay login returned {$response->status()}"
            );
        }
        if (!$response->successful()) {
            throw new PaymentException(
                "NassPay login failed: HTTP {$response->status()}"
            );
        }

        // The login response is data-wrapped (per the Postman collection); a
        // top-level access_token is accepted as a defensive fallback.
        $token = (string) ($response->json('data.access_token')
            ?? $response->json('access_token')
            ?? '');
        if ($token === '') {
            throw new PaymentException('NassPay login returned no access_token');
        }

        $ttl = max(1, $this->ttl - self::SAFETY_MARGIN);
        Cache::put($this->cacheKey, $token, $ttl);
        return $token;
    }
}
