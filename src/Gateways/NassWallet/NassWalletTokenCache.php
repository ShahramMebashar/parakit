<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\NassWallet;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\PaymentException;

/**
 * Bearer-token cache for the NassWallet gateway.
 *
 * NassWallet's login endpoint authenticates with a static `Basic` token (the
 * same for every merchant) plus the per-merchant username/password in a
 * data-wrapped body. The response carries `accessTokenExpiry` as epoch
 * milliseconds, from which the cache TTL is derived.
 */
final class NassWalletTokenCache
{
    /** Expire the cached token this many seconds before NassWallet would. */
    private const SAFETY_MARGIN = 60;

    private readonly string $cacheKey;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $basicToken,
        private readonly string $username,
        private readonly string $password,
    ) {
        // Scope the cache key to base URL + username so two NassWallet configs
        // never share a token. xxh3 is fast and non-cryptographic — uniqueness
        // is all that is needed here.
        $this->cacheKey = 'parakit:nasswallet:token:' . hash('xxh3', $this->baseUrl . '|' . $this->username);
    }

    public function token(): string
    {
        $cached = Cache::get($this->cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        return $this->login();
    }

    /** Drop the cached token — used by NassWalletClient after a 401. */
    public function forget(): void
    {
        Cache::forget($this->cacheKey);
    }

    private function login(): string
    {
        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => 'Basic ' . $this->basicToken])
            ->timeout((int) config('parakit.reliability.timeout_seconds', 15))
            ->post($this->baseUrl . '/login', [
                'data' => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'grantType' => 'password',
                ],
            ]);

        // 5xx is transient — let the caller's retry layer handle it.
        if ($response->status() >= 500) {
            throw new GatewayUnavailableException(
                "NassWallet login returned {$response->status()}"
            );
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // Success is responseCode 0 — errCode is "1" even on success, so it
        // cannot be used as the success signal.
        if (!$response->successful() || ($json['responseCode'] ?? null) !== 0) {
            $message = is_string($json['message'] ?? null) ? $json['message'] : "HTTP {$response->status()}";
            throw new PaymentException("NassWallet login failed: {$message}");
        }

        $data = (array) ($json['data'] ?? []);
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new PaymentException('NassWallet login returned no access_token');
        }

        Cache::put($this->cacheKey, $token, $this->ttlFromExpiry($data['accessTokenExpiry'] ?? null));
        return $token;
    }

    /**
     * Derive a cache TTL from NassWallet's `accessTokenExpiry` (epoch ms).
     * Falls back to a short TTL when the field is missing or unparsable, and
     * never returns less than 30s so a burst-protection window always exists.
     */
    private function ttlFromExpiry(mixed $expiry): int
    {
        $expiryMs = is_numeric($expiry) ? (int) $expiry : 0;
        if ($expiryMs <= 0) {
            return 300;
        }

        return max(30, intdiv($expiryMs, 1000) - time() - self::SAFETY_MARGIN);
    }
}
