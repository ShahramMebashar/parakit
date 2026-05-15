<?php
declare(strict_types=1);

namespace Gutian\Parakit\Gateways\Fib;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Gutian\Parakit\Exceptions\GatewayUnavailableException;

final class FibTokenCache
{
    private const SAFETY_MARGIN = 60;

    private readonly string $cacheKey;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
        // Scope the token cache key to the OAuth realm + client so two FIB
        // configs pointing at different endpoints or client IDs never share
        // a cached token. xxh3 is fast and non-cryptographic — uniqueness is
        // all that's needed here.
        $this->cacheKey = 'parakit:fib:token:' . hash('xxh3', $this->baseUrl . '|' . $this->clientId);
    }

    public function token(): string
    {
        $cached = Cache::get($this->cacheKey);
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
        Cache::put($this->cacheKey, $token, $ttl);
        return $token;
    }
}
