<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

/**
 * OAuth2 client_credentials token cache for ZainCash v2.
 *
 * Modeled on FibTokenCache: the access token is cached until shortly before
 * expiry so repeated charges within a token's lifetime reuse it instead of
 * hammering the /oauth2/token endpoint.
 */
final class ZainCashTokenCache
{
    private const SAFETY_MARGIN = 60;

    private readonly string $cacheKey;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $scope,
    ) {
        // Scope the cache key to realm + client + scope so two configs never
        // share a token. xxh3 is fast and non-cryptographic — uniqueness only.
        $this->cacheKey = 'parakit:zaincash:token:'
            . hash('xxh3', $this->baseUrl . '|' . $this->clientId . '|' . $this->scope);
    }

    public function token(): string
    {
        $cached = Cache::get($this->cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout((int) config('parakit.reliability.timeout_seconds', 15))
            ->post(rtrim($this->baseUrl, '/') . '/oauth2/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => $this->scope,
            ]);

        if ($response->status() >= 500) {
            throw new GatewayUnavailableException(
                "ZainCash token endpoint returned {$response->status()}"
            );
        }
        if (!$response->successful()) {
            throw new ZainCashApiException(
                "ZainCash token endpoint rejected: HTTP {$response->status()}",
                $response->status(),
            );
        }

        $token = (string) $response->json('access_token');
        if ($token === '') {
            throw new ZainCashApiException(
                'ZainCash token endpoint returned no access_token',
                $response->status(),
            );
        }
        $expiresIn = (int) $response->json('expires_in', 600);
        $ttl = max(1, $expiresIn - self::SAFETY_MARGIN);
        Cache::put($this->cacheKey, $token, $ttl);

        return $token;
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey);
    }
}
