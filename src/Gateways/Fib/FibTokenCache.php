<?php
declare(strict_types=1);

namespace Shah\Parakit\Gateways\Fib;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shah\Parakit\Exceptions\GatewayUnavailableException;

final class FibTokenCache
{
    private const CACHE_KEY = 'parakit:fib:token';
    private const SAFETY_MARGIN = 60;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout((int) config('parakit.reliability.timeout_seconds', 15))
            ->post($this->baseUrl . '/protocol/openid-connect/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        if (!$response->successful()) {
            throw new GatewayUnavailableException("FIB token endpoint returned {$response->status()}");
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 600);
        // Guard the TTL: never let it drop to 0 or below (which means "delete
        // now" or "forever" depending on the cache store). 30s minimum keeps
        // a brief burst-protection window even if FIB hands us very short
        // tokens; otherwise we'd hammer the token endpoint.
        $ttl = max(30, $expiresIn - self::SAFETY_MARGIN);
        Cache::put(self::CACHE_KEY, $token, $ttl);
        return $token;
    }
}
