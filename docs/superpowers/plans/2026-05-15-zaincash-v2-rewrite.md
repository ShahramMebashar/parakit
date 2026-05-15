# ZainCash Payment Gateway v2 Rewrite — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the legacy ZainCash v1 integration with the v2 Payment Gateway API (OAuth2 Bearer auth, JSON init/inquiry/reverse, HS256-verified callbacks).

**Architecture:** Mirror the existing FIB gateway: a `Gateway` orchestrator delegating HTTP to a `Client`, OAuth tokens to a cached `TokenCache`, status/error strings to dedicated maps, and callback JWTs to a decode-only `Jwt` helper. The gateway extends `AbstractGateway` and implements `SupportsStatusCheck` + `SupportsRefund`.

**Tech Stack:** PHP 8.2+, Laravel (illuminate/support, illuminate/http), Pest, `firebase/php-jwt`, `ramsey/uuid` (transitive via illuminate/support).

**Spec:** `docs/superpowers/specs/2026-05-15-zaincash-v2-rewrite-design.md`

---

## File structure

| File | Responsibility | Action |
|------|----------------|--------|
| `config/parakit.php` | `zaincash` config block | Modify |
| `src/Gateways/ZainCash/ZainCashJwt.php` | HS256 decode-only callback verification | Rewrite |
| `src/Gateways/ZainCash/ZainCashTokenCache.php` | OAuth2 `client_credentials` token cache | Create |
| `src/Gateways/ZainCash/ZainCashStatusMap.php` | v2 status string → `PaymentStatus` | Create |
| `src/Gateways/ZainCash/ZainCashErrorMap.php` | error string → `PaymentErrorCode` | Rewrite |
| `src/Gateways/ZainCash/ZainCashClient.php` | HTTP: init / inquiry / reverse | Create |
| `src/Gateways/ZainCash/ZainCashGateway.php` | Orchestration | Rewrite |
| `tests/Unit/Gateways/ZainCash/ZainCashJwtTest.php` | JWT decode tests | Rewrite |
| `tests/Unit/Gateways/ZainCash/ZainCashStatusMapTest.php` | status map tests | Create |
| `tests/Unit/Gateways/ZainCash/ZainCashErrorMapTest.php` | error map tests | Rewrite |
| `tests/Feature/Gateways/ZainCash/ZainCashChargeTest.php` | charge + token caching | Rewrite |
| `tests/Feature/Gateways/ZainCash/ZainCashStatusTest.php` | inquiry tests | Rewrite |
| `tests/Feature/Gateways/ZainCash/ZainCashRefundTest.php` | reverse tests | Create |
| `tests/Feature/Gateways/ZainCash/ZainCashWebhookTest.php` | callback tests | Rewrite |

`src/PaymentManager.php` already constructs `new ZainCashGateway($name, $cfg)` with the `(string $name, array $config)` signature — no change needed.

---

## Task 1: Config block + decode-only JWT helper

**Files:**
- Modify: `config/parakit.php:43-51`
- Rewrite: `src/Gateways/ZainCash/ZainCashJwt.php`
- Rewrite: `tests/Unit/Gateways/ZainCash/ZainCashJwtTest.php`

- [ ] **Step 1: Replace the `zaincash` config block**

In `config/parakit.php`, replace the entire existing `'zaincash' => [ ... ]` block with:

```php
        'zaincash' => [
            'driver'        => 'zaincash',
            'base_url'      => env('ZAINCASH_BASE_URL', 'https://pg-api-uat.zaincash.iq'),
            'client_id'     => env('ZAINCASH_CLIENT_ID'),
            'client_secret' => env('ZAINCASH_CLIENT_SECRET'),
            'api_key'       => env('ZAINCASH_API_KEY'),
            'scope'         => env('ZAINCASH_SCOPE', 'payment:read payment:write reverse:write'),
            'service_type'  => env('ZAINCASH_SERVICE_TYPE', 'Delivery'),
            'lang'          => env('ZAINCASH_LANG', 'en'),
            'success_url'   => env('ZAINCASH_SUCCESS_URL'),
            'failure_url'   => env('ZAINCASH_FAILURE_URL'),
        ],
```

- [ ] **Step 2: Write the failing JWT test**

Replace the entire contents of `tests/Unit/Gateways/ZainCash/ZainCashJwtTest.php`:

```php
<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Froshly\Parakit\Gateways\ZainCash\ZainCashJwt;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;

const ZC_SECRET = 'shared-secret-shared-secret-1234';

it('decodes a JWT signed with the matching secret', function () {
    $token = JWT::encode(['eventId' => 'e1', 'data' => ['orderId' => 'ord_1']], ZC_SECRET, 'HS256');
    $claims = (new ZainCashJwt(ZC_SECRET))->decode($token);

    expect($claims['eventId'])->toBe('e1')
        ->and($claims['data']['orderId'])->toBe('ord_1');
});

it('rejects a JWT signed with the wrong secret', function () {
    $token = JWT::encode(['eventId' => 'e1'], 'other-secret-other-secret-aaaaaa', 'HS256');
    (new ZainCashJwt(ZC_SECRET))->decode($token);
})->throws(InvalidWebhookSignatureException::class);

it('rejects a tampered JWT', function () {
    $token = JWT::encode(['eventId' => 'e1'], ZC_SECRET, 'HS256');
    (new ZainCashJwt(ZC_SECRET))->decode(substr($token, 0, -5) . 'XXXXX');
})->throws(InvalidWebhookSignatureException::class);

it('rejects an alg=none JWT (algorithm confusion attack)', function () {
    $header = strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_');
    $payload = strtr(base64_encode(json_encode(['eventId' => 'e1'])), '+/', '-_');
    (new ZainCashJwt(ZC_SECRET))->decode($header . '.' . $payload . '.');
})->throws(InvalidWebhookSignatureException::class);
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Gateways/ZainCash/ZainCashJwtTest.php`
Expected: FAIL — current `ZainCashJwt` still has `encode()`, tests reference new behavior; the alg=none / decode signature differs.

- [ ] **Step 4: Rewrite `ZainCashJwt` as decode-only**

Replace the entire contents of `src/Gateways/ZainCash/ZainCashJwt.php`:

```php
<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;

/**
 * HS256-pinned JWT verifier for ZainCash v2 redirect and webhook callbacks.
 *
 * ZainCash v2 signs callbacks with the merchant API key using HS256. Algorithm
 * is pinned at decode time — `alg: none` and asymmetric algorithms are rejected
 * by firebase/php-jwt when only the symmetric key is supplied, defending
 * against algorithm-confusion attacks. There is no encode(): v2 never requires
 * the merchant to sign outbound requests (those use an OAuth2 Bearer token).
 */
final class ZainCashJwt
{
    private const ALG = 'HS256';

    public function __construct(private readonly string $secret) {}

    /** @return array<string, mixed> */
    public function decode(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, self::ALG));
        } catch (\Throwable $e) {
            throw new InvalidWebhookSignatureException(
                'ZainCash JWT invalid: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return (array) json_decode((string) json_encode($decoded), true);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Gateways/ZainCash/ZainCashJwtTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add config/parakit.php src/Gateways/ZainCash/ZainCashJwt.php tests/Unit/Gateways/ZainCash/ZainCashJwtTest.php
git commit -m "feat(zaincash): v2 config block and decode-only JWT verifier"
```

---

## Task 2: OAuth2 token cache

**Files:**
- Create: `src/Gateways/ZainCash/ZainCashTokenCache.php`
- Test: `tests/Feature/Gateways/ZainCash/ZainCashTokenCacheTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gateways/ZainCash/ZainCashTokenCacheTest.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\ZainCash\ZainCashTokenCache;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

beforeEach(fn () => Cache::flush());

it('fetches and caches an OAuth2 token, reusing it on the second call', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600], 200),
    ]);

    $cache = new ZainCashTokenCache('https://pg-api-uat.zaincash.iq', 'cid', 'csecret', 'payment:read');

    expect($cache->token())->toBe('tok_1')
        ->and($cache->token())->toBe('tok_1');

    Http::assertSentCount(1);
});

it('throws GatewayUnavailableException when the token endpoint fails', function () {
    Http::fake(['*/oauth2/token' => Http::response('nope', 500)]);

    (new ZainCashTokenCache('https://pg-api-uat.zaincash.iq', 'cid', 'csecret', 'payment:read'))->token();
})->throws(GatewayUnavailableException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashTokenCacheTest.php`
Expected: FAIL with "Class ZainCashTokenCache not found"

- [ ] **Step 3: Create `ZainCashTokenCache`**

Create `src/Gateways/ZainCash/ZainCashTokenCache.php`:

```php
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

        if (!$response->successful()) {
            throw new GatewayUnavailableException(
                "ZainCash token endpoint returned {$response->status()}"
            );
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 600);
        // Never let the TTL hit 0 or below (which means "delete now" / "forever"
        // depending on the cache store). 30s minimum keeps a burst-protection
        // window even if ZainCash hands us very short-lived tokens.
        $ttl = max(30, $expiresIn - self::SAFETY_MARGIN);
        Cache::put($this->cacheKey, $token, $ttl);

        return $token;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashTokenCacheTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashTokenCache.php tests/Feature/Gateways/ZainCash/ZainCashTokenCacheTest.php
git commit -m "feat(zaincash): OAuth2 client_credentials token cache"
```

---

## Task 3: Status map

**Files:**
- Create: `src/Gateways/ZainCash/ZainCashStatusMap.php`
- Test: `tests/Unit/Gateways/ZainCash/ZainCashStatusMapTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Gateways/ZainCash/ZainCashStatusMapTest.php`:

```php
<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\ZainCash\ZainCashStatusMap;
use Froshly\Parakit\Enums\PaymentStatus;

it('maps every documented v2 status string', function () {
    expect(ZainCashStatusMap::toStatus('SUCCESS'))->toBe(PaymentStatus::Paid)
        ->and(ZainCashStatusMap::toStatus('FAILED'))->toBe(PaymentStatus::Failed)
        ->and(ZainCashStatusMap::toStatus('PENDING'))->toBe(PaymentStatus::Pending)
        ->and(ZainCashStatusMap::toStatus('OTP_SENT'))->toBe(PaymentStatus::Pending)
        ->and(ZainCashStatusMap::toStatus('CUSTOMER_AUTHENTICATION_REQUIRED'))->toBe(PaymentStatus::Pending)
        ->and(ZainCashStatusMap::toStatus('EXPIRED'))->toBe(PaymentStatus::Expired)
        ->and(ZainCashStatusMap::toStatus('REFUNDED'))->toBe(PaymentStatus::Refunded);
});

it('is case-insensitive', function () {
    expect(ZainCashStatusMap::toStatus('success'))->toBe(PaymentStatus::Paid);
});

it('falls back to Pending for unknown status strings', function () {
    expect(ZainCashStatusMap::toStatus('SOMETHING_NEW'))->toBe(PaymentStatus::Pending);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Gateways/ZainCash/ZainCashStatusMapTest.php`
Expected: FAIL with "Class ZainCashStatusMap not found"

- [ ] **Step 3: Create `ZainCashStatusMap`**

Create `src/Gateways/ZainCash/ZainCashStatusMap.php`:

```php
<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Illuminate\Support\Facades\Log;
use Froshly\Parakit\Enums\PaymentStatus;

final class ZainCashStatusMap
{
    private const MAP = [
        'SUCCESS'                          => PaymentStatus::Paid,
        'FAILED'                           => PaymentStatus::Failed,
        'PENDING'                          => PaymentStatus::Pending,
        'OTP_SENT'                         => PaymentStatus::Pending,
        'CUSTOMER_AUTHENTICATION_REQUIRED' => PaymentStatus::Pending,
        'EXPIRED'                          => PaymentStatus::Expired,
        'REFUNDED'                         => PaymentStatus::Refunded,
    ];

    public static function toStatus(string $raw): PaymentStatus
    {
        $upper = strtoupper($raw);
        if (isset(self::MAP[$upper])) {
            return self::MAP[$upper];
        }
        // Surface ZainCash API drift: a new status string should be visible to
        // operators before every transaction silently becomes Pending.
        Log::warning('parakit.zaincash.unknown_status', ['raw' => $raw]);
        return PaymentStatus::Pending;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Gateways/ZainCash/ZainCashStatusMapTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashStatusMap.php tests/Unit/Gateways/ZainCash/ZainCashStatusMapTest.php
git commit -m "feat(zaincash): v2 status map"
```

---

## Task 4: Error map

**Files:**
- Rewrite: `src/Gateways/ZainCash/ZainCashErrorMap.php`
- Rewrite: `tests/Unit/Gateways/ZainCash/ZainCashErrorMapTest.php`

- [ ] **Step 1: Write the failing test**

Replace the entire contents of `tests/Unit/Gateways/ZainCash/ZainCashErrorMapTest.php`:

```php
<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\ZainCash\ZainCashErrorMap;
use Froshly\Parakit\Enums\PaymentErrorCode;

it('maps v2 error codes', function () {
    expect(ZainCashErrorMap::toCode('PAYMENT_GATEWAY_UNAUTHORIZED'))->toBe(PaymentErrorCode::InvalidCredentials)
        ->and(ZainCashErrorMap::toCode('PAYMENT_GATEWAY_TRANSACTION_NOT_FOUND'))->toBe(PaymentErrorCode::Unknown);
});

it('maps common error substrings', function () {
    expect(ZainCashErrorMap::toCode('Insufficient Balance'))->toBe(PaymentErrorCode::InsufficientFunds)
        ->and(ZainCashErrorMap::toCode('cancelled by user'))->toBe(PaymentErrorCode::UserCancelled)
        ->and(ZainCashErrorMap::toCode('transaction expired'))->toBe(PaymentErrorCode::Expired)
        ->and(ZainCashErrorMap::toCode('request timeout'))->toBe(PaymentErrorCode::Timeout)
        ->and(ZainCashErrorMap::toCode('something else'))->toBe(PaymentErrorCode::Unknown);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Gateways/ZainCash/ZainCashErrorMapTest.php`
Expected: FAIL — `PAYMENT_GATEWAY_UNAUTHORIZED` currently maps to `Unknown`, not `InvalidCredentials`.

- [ ] **Step 3: Rewrite `ZainCashErrorMap`**

Replace the entire contents of `src/Gateways/ZainCash/ZainCashErrorMap.php`:

```php
<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Froshly\Parakit\Enums\PaymentErrorCode;

final class ZainCashErrorMap
{
    public static function toCode(string $raw): PaymentErrorCode
    {
        $r = strtolower($raw);

        return match (true) {
            str_contains($r, 'unauthorized')  => PaymentErrorCode::InvalidCredentials,
            str_contains($r, 'insufficient')  => PaymentErrorCode::InsufficientFunds,
            str_contains($r, 'cancel')        => PaymentErrorCode::UserCancelled,
            str_contains($r, 'expire')        => PaymentErrorCode::Expired,
            str_contains($r, 'timeout')       => PaymentErrorCode::Timeout,
            str_contains($r, 'phone')         => PaymentErrorCode::InvalidPhone,
            str_contains($r, 'amount')        => PaymentErrorCode::InvalidAmount,
            default                           => PaymentErrorCode::Unknown,
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Gateways/ZainCash/ZainCashErrorMapTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashErrorMap.php tests/Unit/Gateways/ZainCash/ZainCashErrorMapTest.php
git commit -m "feat(zaincash): v2 error map"
```

---

## Task 5: HTTP client

**Files:**
- Create: `src/Gateways/ZainCash/ZainCashClient.php`
- Test: `tests/Feature/Gateways/ZainCash/ZainCashClientTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gateways/ZainCash/ZainCashClientTest.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\ZainCash\ZainCashClient;
use Froshly\Parakit\Gateways\ZainCash\ZainCashTokenCache;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

beforeEach(fn () => Cache::flush());

function zcClient(): ZainCashClient
{
    return new ZainCashClient(
        'https://pg-api-uat.zaincash.iq',
        new ZainCashTokenCache('https://pg-api-uat.zaincash.iq', 'cid', 'csecret', 'payment:read'),
        15,
    );
}

it('posts init with a Bearer token and returns the decoded body', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/init' => Http::response(['redirectUrl' => 'https://pay/x'], 200),
    ]);

    $body = zcClient()->init(['orderId' => 'ord_1']);

    expect($body['redirectUrl'])->toBe('https://pay/x');
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/v2/payment-gateway/transaction/init')
        && $req->hasHeader('Authorization', 'Bearer tok_1'));
});

it('GETs the inquiry endpoint by transactionId', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/inquiry/*' => Http::response(['status' => 'SUCCESS'], 200),
    ]);

    expect(zcClient()->inquiry('zc_1')['status'])->toBe('SUCCESS');
    Http::assertSent(fn ($req) =>
        $req->method() === 'GET'
        && str_contains($req->url(), '/transaction/inquiry/zc_1'));
});

it('posts reverse with transactionId and reason', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/reverse' => Http::response(['status' => 'COMPLETED'], 200),
    ]);

    expect(zcClient()->reverse('zc_1', 'duplicate order')['status'])->toBe('COMPLETED');
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/reverse')
        && $req['transactionId'] === 'zc_1'
        && $req['reason'] === 'duplicate order');
});

it('throws GatewayUnavailableException on a non-2xx response', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/init' => Http::response('boom', 500),
    ]);

    zcClient()->init(['orderId' => 'ord_1']);
})->throws(GatewayUnavailableException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashClientTest.php`
Expected: FAIL with "Class ZainCashClient not found"

- [ ] **Step 3: Create `ZainCashClient`**

Create `src/Gateways/ZainCash/ZainCashClient.php`:

```php
<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;

/**
 * Bearer-authenticated HTTP client for the ZainCash v2 Payment Gateway.
 *
 * All requests and responses are JSON; the OAuth2 access token is supplied by
 * ZainCashTokenCache. Non-2xx responses are surfaced as GatewayUnavailable
 * so AbstractGateway's retry/circuit-breaker logic engages.
 */
final class ZainCashClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ZainCashTokenCache $tokens,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function init(array $payload): array
    {
        $res = $this->client()->post('/api/v2/payment-gateway/transaction/init', $payload);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash init failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function inquiry(string $transactionId): array
    {
        $res = $this->client()->get("/api/v2/payment-gateway/transaction/inquiry/{$transactionId}");
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash inquiry failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    public function reverse(string $transactionId, string $reason): array
    {
        $res = $this->client()->post('/api/v2/payment-gateway/transaction/reverse', [
            'transactionId' => $transactionId,
            'reason' => $reason,
        ]);
        if (!$res->successful()) {
            throw new GatewayUnavailableException("ZainCash reverse failed: HTTP {$res->status()}");
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->tokens->token())
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashClientTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashClient.php tests/Feature/Gateways/ZainCash/ZainCashClientTest.php
git commit -m "feat(zaincash): v2 HTTP client (init/inquiry/reverse)"
```

---

## Task 6: Gateway — constructor + charge

**Files:**
- Rewrite: `src/Gateways/ZainCash/ZainCashGateway.php`
- Rewrite: `tests/Feature/Gateways/ZainCash/ZainCashChargeTest.php`

The gateway is rewritten in full here (constructor, `performCharge`, `normalizeLang`). `status()`, `refund()`, and `handleWebhook()` are added as throwing stubs so the class satisfies its interfaces and stays compilable; Tasks 7–9 replace each stub test-first.

- [ ] **Step 1: Write the failing charge test**

Replace the entire contents of `tests/Feature/Gateways/ZainCash/ZainCashChargeTest.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.zaincash', [
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => 'shared-secret-shared-secret-1234',
        'scope'         => 'payment:read payment:write reverse:write',
        'service_type'  => 'Delivery',
        'lang'          => 'en',
        'success_url'   => 'https://app.test/success',
        'failure_url'   => 'https://app.test/failure',
    ]);
});

function fakeZcInit(): void
{
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/init' => Http::response([
            'status' => 'SUCCESS',
            'transactionDetails' => [
                'transactionId' => 'zc_1',
                'orderId' => 'ord_1',
                'amount' => ['currency' => 'IQD', 'value' => 5000],
            ],
            'redirectUrl' => 'https://pg-api-uat.zaincash.iq/transaction/pay?id=zc_1&token=t',
            'expiryTime' => '2026-05-15T08:04:27.402+00:00',
        ], 200),
    ]);
}

it('creates a v2 transaction and returns the gateway redirect URL verbatim', function () {
    fakeZcInit();

    $r = Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_1',
        amount: 5000,
        currency: Currency::IQD,
        description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->gatewayTransactionId)->toBe('zc_1')
        ->and($r->redirectUrl)->toBe('https://pg-api-uat.zaincash.iq/transaction/pay?id=zc_1&token=t');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/v2/payment-gateway/transaction/init')
        && $req['orderId'] === 'ord_1'
        && $req['language'] === 'En'
        && $req['serviceType'] === 'Delivery'
        && $req['amount']['value'] === '5000'
        && $req['amount']['currency'] === 'IQD'
        && $req['redirectUrls']['successUrl'] === 'https://app.test/success'
        && $req['redirectUrls']['failureUrl'] === 'https://app.test/failure'
        && is_string($req['externalReferenceId'])
        && preg_match('/^[0-9a-f-]{36}$/', $req['externalReferenceId']) === 1);
});

it('omits customer.phone when no phone is supplied and includes it when present', function () {
    fakeZcInit();

    Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_2', amount: 5000, currency: Currency::IQD, description: 'd',
        customerPhone: '9647801234567',
    ));

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/init')
        && ($req['customer']['phone'] ?? null) === '9647801234567');
});

it('overrides serviceType from request metadata', function () {
    fakeZcInit();

    Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_3', amount: 5000, currency: Currency::IQD, description: 'd',
        metadata: ['service_type' => 'Subscription'],
    ));

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/init')
        && $req['serviceType'] === 'Subscription');
});

it('reuses the same externalReferenceId for the same idempotency key', function () {
    fakeZcInit();

    $req = fn () => new PaymentRequest(
        reference: 'ord_4', amount: 5000, currency: Currency::IQD, description: 'd',
        idempotencyKey: 'fixed-key-1',
    );

    Payment::driver('zaincash')->charge($req());
    Cache::flush(); // drop the idempotency cache so performCharge runs again
    Payment::driver('zaincash')->charge($req());

    $seen = [];
    Http::assertSent(function ($request) use (&$seen) {
        if (str_contains($request->url(), '/transaction/init')) {
            $seen[] = $request['externalReferenceId'];
        }
        return true;
    });

    expect($seen)->toHaveCount(2)
        ->and($seen[0])->toBe($seen[1]);
});

it('fails when the init response lacks transactionId or redirectUrl', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/init' => Http::response(['status' => 'SUCCESS'], 200),
    ]);

    Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_5', amount: 5000, currency: Currency::IQD, description: 'd',
    ));
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashChargeTest.php`
Expected: FAIL — the current `ZainCashGateway` posts a JWT to `/transaction/init`, not v2 JSON.

- [ ] **Step 3: Rewrite `ZainCashGateway`**

Replace the entire contents of `src/Gateways/ZainCash/ZainCashGateway.php`:

```php
<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\DTOs\RefundResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Gateways\AbstractGateway;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Support\IdempotencyKey;

final class ZainCashGateway extends AbstractGateway implements SupportsStatusCheck, SupportsRefund
{
    private readonly ZainCashJwt $jwt;
    private readonly ZainCashClient $client;

    public function __construct(string $name, array $config)
    {
        parent::__construct($name, $config);

        // api_key verifies callback JWTs; client_secret authenticates the
        // OAuth2 token endpoint — two distinct secrets in v2.
        $this->jwt = new ZainCashJwt((string) $config['api_key']);
        $this->client = new ZainCashClient(
            baseUrl: (string) $config['base_url'],
            tokens: new ZainCashTokenCache(
                (string) $config['base_url'],
                (string) $config['client_id'],
                (string) $config['client_secret'],
                (string) ($config['scope'] ?? 'payment:read payment:write reverse:write'),
            ),
            timeoutSeconds: (int) config('parakit.reliability.timeout_seconds', 15),
        );
    }

    protected function performCharge(PaymentRequest $request): PaymentResponse
    {
        // externalReferenceId must be stable across AbstractGateway retries —
        // a random UUID per call would create duplicate ZainCash transactions.
        // Deriving a UUIDv5 from the framework idempotency key keeps it stable.
        $idemKey = $request->idempotencyKey ?? IdempotencyKey::derive(
            $this->name(),
            $request->reference,
            $request->amount,
            $request->currency->value,
        );
        $externalReferenceId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'parakit:zaincash:' . $idemKey,
        )->toString();

        $serviceType = (string) ($request->metadata['service_type']
            ?? $this->config['service_type']
            ?? 'Delivery');

        $payload = [
            'language' => $this->normalizeLang((string) ($this->config['lang'] ?? 'en')),
            'externalReferenceId' => $externalReferenceId,
            'orderId' => $request->reference,
            'serviceType' => $serviceType,
            'amount' => [
                'value' => (string) $request->amount,
                'currency' => Currency::IQD->value,
            ],
            'redirectUrls' => [
                'successUrl' => $request->returnUrl ?? (string) ($this->config['success_url'] ?? ''),
                'failureUrl' => (string) ($this->config['failure_url'] ?? ''),
            ],
        ];
        if ($request->customerPhone !== null && $request->customerPhone !== '') {
            $payload['customer'] = ['phone' => $request->customerPhone];
        }

        $raw = $this->client->init($payload);

        $transactionId = $raw['transactionDetails']['transactionId'] ?? null;
        $redirectUrl = $raw['redirectUrl'] ?? null;
        if (!is_string($transactionId) || $transactionId === ''
            || !is_string($redirectUrl) || $redirectUrl === '') {
            throw new GatewayUnavailableException(
                'ZainCash init returned no transactionId/redirectUrl'
            );
        }

        return new PaymentResponse(
            success: true,
            gateway: $this->name(),
            gatewayTransactionId: $transactionId,
            reference: $request->reference,
            status: PaymentStatus::Pending,
            amount: $request->amount,
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            redirectUrl: $redirectUrl,
            expiresAt: isset($raw['expiryTime'])
                ? new DateTimeImmutable((string) $raw['expiryTime'])
                : null,
            raw: $raw,
        );
    }

    public function status(string $gatewayTransactionId): PaymentResponse
    {
        throw new \RuntimeException('not implemented');
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        throw new \RuntimeException('not implemented');
    }

    public function handleWebhook(Request $request): WebhookPayload
    {
        throw new \RuntimeException('not implemented');
    }

    /**
     * Normalize an application locale to a ZainCash v2 language code.
     *
     * The v2 doc's params table specifies En/Ar/Ku; its curl examples send
     * lowercase. We follow the documented contract (title-case) — confirm
     * against UAT and switch if the gateway rejects it.
     */
    private function normalizeLang(string $lang): string
    {
        return match (strtolower($lang)) {
            'ar' => 'Ar',
            'ku' => 'Ku',
            default => 'En',
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashChargeTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashGateway.php tests/Feature/Gateways/ZainCash/ZainCashChargeTest.php
git commit -m "feat(zaincash): v2 gateway charge flow"
```

---

## Task 7: Gateway — status (inquiry)

**Files:**
- Modify: `src/Gateways/ZainCash/ZainCashGateway.php` (`status()` method)
- Rewrite: `tests/Feature/Gateways/ZainCash/ZainCashStatusTest.php`

- [ ] **Step 1: Write the failing test**

Replace the entire contents of `tests/Feature/Gateways/ZainCash/ZainCashStatusTest.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.zaincash', [
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => 'shared-secret-shared-secret-1234',
    ]);
});

it('reads transaction status via the v2 inquiry endpoint', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/inquiry/*' => Http::response([
            'status' => 'SUCCESS',
            'transactionDetails' => [
                'transactionId' => 'zc_1',
                'orderId' => 'ord_1',
                'amount' => ['currency' => 'IQD', 'value' => 5000],
            ],
        ], 200),
    ]);

    $driver = Payment::driver('zaincash');
    expect($driver)->toBeInstanceOf(SupportsStatusCheck::class);

    $resp = $driver->status('zc_1');

    expect($resp->status)->toBe(PaymentStatus::Paid)
        ->and($resp->gatewayTransactionId)->toBe('zc_1')
        ->and($resp->reference)->toBe('ord_1')
        ->and($resp->amount)->toBe(5000);

    Http::assertSent(fn ($req) =>
        $req->method() === 'GET'
        && str_contains($req->url(), '/transaction/inquiry/zc_1'));
});

it('maps an OTP_SENT inquiry to Pending', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/inquiry/*' => Http::response(['status' => 'OTP_SENT'], 200),
    ]);

    expect(Payment::driver('zaincash')->status('zc_1')->status)->toBe(PaymentStatus::Pending);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashStatusTest.php`
Expected: FAIL with "not implemented" (`RuntimeException` from the stub)

- [ ] **Step 3: Replace the `status()` stub**

In `src/Gateways/ZainCash/ZainCashGateway.php`, replace the `status()` method body:

```php
    public function status(string $gatewayTransactionId): PaymentResponse
    {
        $raw = $this->client->inquiry($gatewayTransactionId);

        $status = ZainCashStatusMap::toStatus((string) ($raw['status'] ?? ''));
        $details = (array) ($raw['transactionDetails'] ?? []);
        $amountInfo = (array) ($details['amount'] ?? []);

        return new PaymentResponse(
            success: $status->isSuccessful() || $status === PaymentStatus::Pending,
            gateway: $this->name(),
            gatewayTransactionId: $gatewayTransactionId,
            reference: (string) ($details['orderId'] ?? ''),
            status: $status,
            amount: (int) ($amountInfo['value'] ?? 0),
            currency: Currency::IQD,
            correlationId: $this->correlationId(),
            raw: $raw,
        );
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashStatusTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashGateway.php tests/Feature/Gateways/ZainCash/ZainCashStatusTest.php
git commit -m "feat(zaincash): v2 transaction inquiry"
```

---

## Task 8: Gateway — refund (reverse)

**Files:**
- Modify: `src/Gateways/ZainCash/ZainCashGateway.php` (`refund()` method)
- Create: `tests/Feature/Gateways/ZainCash/ZainCashRefundTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Gateways/ZainCash/ZainCashRefundTest.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Models\PaymentTransaction;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.zaincash', [
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => 'shared-secret-shared-secret-1234',
    ]);

    PaymentTransaction::create([
        'gateway' => 'zaincash',
        'reference' => 'ord_1',
        'gateway_transaction_id' => 'zc_1',
        'status' => PaymentStatus::Paid,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => 'c',
    ]);
});

it('reverses a transaction in full and returns the reversal reference', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/reverse' => Http::response([
            'status' => 'COMPLETED',
            'reversalReferenceId' => 'rev_1',
            'amount' => 5000,
        ], 200),
    ]);

    $driver = Payment::driver('zaincash');
    expect($driver)->toBeInstanceOf(SupportsRefund::class);

    $resp = $driver->refund(new RefundRequest(
        transactionId: 'zc_1',
        amount: 5000,
        reason: 'duplicate order',
    ));

    expect($resp->success)->toBeTrue()
        ->and($resp->refundId)->toBe('rev_1')
        ->and($resp->refundedAmount)->toBe(5000);

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/reverse')
        && $req['transactionId'] === 'zc_1'
        && $req['reason'] === 'duplicate order');
});

it('rejects a partial refund — v2 reverse is full-only', function () {
    Http::fake(['*' => Http::response([], 200)]);

    Payment::driver('zaincash')->refund(new RefundRequest(
        transactionId: 'zc_1',
        amount: 2000,
        reason: 'partial',
    ));
})->throws(InvalidArgumentException::class);

it('treats a non-COMPLETED reverse response as a gateway failure', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/reverse' => Http::response(['status' => 'PENDING'], 200),
    ]);

    Payment::driver('zaincash')->refund(new RefundRequest(
        transactionId: 'zc_1',
        amount: 5000,
    ));
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashRefundTest.php`
Expected: FAIL with "not implemented" (`RuntimeException` from the stub)

- [ ] **Step 3: Replace the `refund()` stub**

In `src/Gateways/ZainCash/ZainCashGateway.php`, replace the `refund()` method body:

```php
    public function refund(RefundRequest $request): RefundResponse
    {
        // v2 reverse is full-refund only — there is no amount parameter. If the
        // caller asked for a partial refund (amount != original charge), reject
        // before touching the gateway. The original amount comes from the
        // persisted transaction row; if no row exists we cannot validate and
        // proceed with a full reverse.
        $tx = PaymentTransaction::query()
            ->where('gateway', $this->name())
            ->where('gateway_transaction_id', $request->transactionId)
            ->first();
        if ($tx !== null && (int) $tx->amount !== $request->amount) {
            throw new \InvalidArgumentException(
                'ZainCash supports full reversals only; refund amount must equal the original charge amount'
            );
        }

        $raw = $this->client->reverse(
            $request->transactionId,
            $request->reason ?? 'Merchant-initiated reversal',
        );

        $reverseStatus = strtoupper((string) ($raw['status'] ?? ''));
        $refundId = $raw['reversalReferenceId'] ?? null;
        if ($reverseStatus !== 'COMPLETED' || !is_string($refundId) || $refundId === '') {
            throw new GatewayUnavailableException(
                "ZainCash reverse did not complete (status: {$reverseStatus})"
            );
        }

        return new RefundResponse(
            success: true,
            refundId: $refundId,
            refundedAmount: $request->amount,
            raw: $raw,
        );
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashRefundTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashGateway.php tests/Feature/Gateways/ZainCash/ZainCashRefundTest.php
git commit -m "feat(zaincash): v2 full reversal/refund"
```

---

## Task 9: Gateway — webhook + redirect callback

**Files:**
- Modify: `src/Gateways/ZainCash/ZainCashGateway.php` (`handleWebhook()` + 2 private helpers)
- Rewrite: `tests/Feature/Gateways/ZainCash/ZainCashWebhookTest.php`

- [ ] **Step 1: Write the failing test**

Replace the entire contents of `tests/Feature/Gateways/ZainCash/ZainCashWebhookTest.php`:

```php
<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

const ZC_WEBHOOK_SECRET = 'shared-secret-shared-secret-1234';

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('parakit.gateways.zaincash', [
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => ZC_WEBHOOK_SECRET,
    ]);
});

function zcCallbackToken(array $overrides = [], string $secret = ZC_WEBHOOK_SECRET): string
{
    $claims = array_replace_recursive([
        'eventType' => 'STATUS_CHANGED',
        'eventId' => 'evt_1',
        'timestamp' => '2026-05-15T10:15:30.000+00:00',
        'data' => [
            'transactionId' => 'zc_1',
            'orderId' => 'ord_1',
            'customerMsisdn' => '9647801234567',
            'currentStatus' => 'SUCCESS',
            'amount' => ['currency' => 'IQD', 'value' => 5000],
        ],
    ], $overrides);

    return JWT::encode($claims, $secret, 'HS256');
}

function seedPendingZcTransaction(): void
{
    PaymentTransaction::create([
        'gateway' => 'zaincash', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'zc_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);
}

it('verifies a redirect callback token (token field) and applies the transition', function () {
    seedPendingZcTransaction();

    $this->postJson('/payments/webhooks/zaincash', ['token' => zcCallbackToken()])
        ->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('verifies a webhook callback token (webhook_token field)', function () {
    seedPendingZcTransaction();

    $this->postJson('/payments/webhooks/zaincash', ['webhook_token' => zcCallbackToken()])
        ->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('maps a STATUS_CHANGED FAILED event to Failed', function () {
    seedPendingZcTransaction();

    $token = zcCallbackToken(['data' => ['currentStatus' => 'FAILED', 'errorMessage' => 'Error!']]);
    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Failed);
});

it('maps a REFUND_COMPLETED event to Refunded', function () {
    PaymentTransaction::create([
        'gateway' => 'zaincash', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'zc_1',
        'status' => PaymentStatus::Paid, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    $token = zcCallbackToken([
        'eventType' => 'REFUND_COMPLETED',
        'data' => ['currentStatus' => 'REFUNDED'],
    ]);
    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Refunded);
});

it('rejects a forged callback token with 401', function () {
    $token = zcCallbackToken(secret: 'wrong-secret-wrong-secret-aaaaa1');

    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(401);
});

it('rejects a request with no token with 401', function () {
    $this->postJson('/payments/webhooks/zaincash', [])->assertStatus(401);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashWebhookTest.php`
Expected: FAIL with "not implemented" (`RuntimeException` from the stub) on the verifying tests.

- [ ] **Step 3: Replace the `handleWebhook()` stub and add helpers**

In `src/Gateways/ZainCash/ZainCashGateway.php`, replace the `handleWebhook()` method body and add the two private helpers immediately after it (before `normalizeLang`):

```php
    /**
     * Verify a ZainCash v2 callback (redirect token or server webhook).
     *
     * Both the redirect (`?token=`) and the webhook (`{webhook_token}`) deliver
     * an HS256 JWT signed with the merchant API key. The JWT signature is the
     * trust boundary — decoding with our shared secret rejects any forged or
     * tampered payload, and the algorithm is pinned in ZainCashJwt.
     */
    public function handleWebhook(Request $request): WebhookPayload
    {
        $token = (string) ($request->input('webhook_token')
            ?? $request->input('token')
            ?? '');
        if ($token === '') {
            throw new InvalidWebhookSignatureException('ZainCash callback missing token');
        }

        $claims = $this->jwt->decode($token);
        $data = (array) ($claims['data'] ?? []);
        $eventType = strtoupper((string) ($claims['eventType'] ?? ''));
        $currentStatus = (string) ($data['currentStatus'] ?? '');

        $status = match ($eventType) {
            'STATUS_CHANGED'   => ZainCashStatusMap::toStatus($currentStatus),
            'REFUND_COMPLETED' => PaymentStatus::Refunded,
            'REFUND_FAILED'    => $this->onRefundFailed($currentStatus),
            default            => $this->onUnknownEvent($eventType, $currentStatus),
        };

        $amountInfo = (array) ($data['amount'] ?? []);
        $transactionId = (string) ($data['transactionId'] ?? '');

        // Prefer ZainCash's own eventId for idempotency; fall back to a derived
        // key only if the claim is absent.
        $eventId = (string) ($claims['eventId'] ?? '');
        if ($eventId === '') {
            $eventId = $transactionId . ':' . $status->value;
        }

        return new WebhookPayload(
            gateway: $this->name(),
            gatewayTransactionId: $transactionId,
            reference: (string) ($data['orderId'] ?? ''),
            status: $status,
            amount: (int) ($amountInfo['value'] ?? 0),
            currency: Currency::IQD,
            eventId: $eventId,
            occurredAt: isset($claims['timestamp'])
                ? new DateTimeImmutable((string) $claims['timestamp'])
                : new DateTimeImmutable(),
            raw: $claims,
        );
    }

    /**
     * A REFUND_FAILED event means the reversal failed; the payment itself is
     * unchanged, so map currentStatus as usual but log the failed reversal.
     */
    private function onRefundFailed(string $currentStatus): PaymentStatus
    {
        Log::warning('parakit.zaincash.refund_failed', ['currentStatus' => $currentStatus]);
        return ZainCashStatusMap::toStatus($currentStatus);
    }

    private function onUnknownEvent(string $eventType, string $currentStatus): PaymentStatus
    {
        Log::warning('parakit.zaincash.unknown_event', ['eventType' => $eventType]);
        return ZainCashStatusMap::toStatus($currentStatus);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Gateways/ZainCash/ZainCashWebhookTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Gateways/ZainCash/ZainCashGateway.php tests/Feature/Gateways/ZainCash/ZainCashWebhookTest.php
git commit -m "feat(zaincash): v2 redirect + webhook callback verification"
```

---

## Task 10: Full-suite + static-analysis verification

**Files:** none (verification only)

- [ ] **Step 1: Run the full ZainCash test directory**

Run: `vendor/bin/pest tests/Unit/Gateways/ZainCash tests/Feature/Gateways/ZainCash`
Expected: PASS — all ZainCash unit + feature tests green.

- [ ] **Step 2: Run the entire test suite**

Run: `vendor/bin/pest`
Expected: PASS — no regressions in FIB, PaymentManager, console, or webhook-controller tests. If any test still references the removed v1 config keys (`merchant_id`, `msisdn`, `secret`, `redirect_url`) or the removed `ZainCashJwt::encode()`, fix it to use the v2 config shape / `Firebase\JWT\JWT::encode`.

- [ ] **Step 3: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: PASS — no new errors in `src/Gateways/ZainCash/`.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "test(zaincash): fix residual v1 references after v2 rewrite"
```

(Skip this commit if Steps 1–3 produced no changes.)

---

## Self-review notes

- **Spec coverage:** config block (T1), decode-only JWT (T1), TokenCache (T2), StatusMap (T3), ErrorMap (T4), Client init/inquiry/reverse (T5), charge with deterministic `externalReferenceId` + serviceType override + customer.phone omission (T6), inquiry status (T7), full-only reverse with partial-reject (T8), dual-field callback handler with `eventType` branching + `eventId` idempotency (T9), full-suite + phpstan (T10). All spec sections covered.
- **`PaymentManager`** already builds `ZainCashGateway($name, $cfg)` — no change required.
- **Type consistency:** `ZainCashClient::{init,inquiry,reverse}`, `ZainCashTokenCache::token()`, `ZainCashStatusMap::toStatus()`, `ZainCashErrorMap::toCode()`, `ZainCashJwt::decode()` are named identically across every task that references them.
- **Known caveats (from spec, not blocking):** refund-event `data{}` shape is assumed identical to `STATUS_CHANGED` (no doc example); `language` casing follows the doc params table and needs UAT confirmation.
