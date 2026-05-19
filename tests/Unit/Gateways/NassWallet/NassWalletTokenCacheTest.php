<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\NassWallet\NassWalletTokenCache;

beforeEach(fn () => Cache::flush());

function nwLoginResponse(string $token = 'tok_nw'): array
{
    return [
        'responseCode' => 0,
        'errCode' => '1',
        'message' => 'Success',
        'data' => [
            'access_token' => $token,
            // Epoch milliseconds, one hour out.
            'accessTokenExpiry' => (string) ((time() + 3600) * 1000),
        ],
    ];
}

function nwTokenCache(string $user = 'merchant'): NassWalletTokenCache
{
    return new NassWalletTokenCache(
        baseUrl: 'https://uatgw1.nasswallet.com',
        basicToken: 'BASIC_TOKEN',
        username: $user,
        password: 'pass',
    );
}

it('logs in with a Basic auth header and a data-wrapped body, then caches the token', function () {
    Http::fake(['*/login' => Http::response(nwLoginResponse(), 200)]);

    expect(nwTokenCache()->token())->toBe('tok_nw');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/login')
        && $req->header('Authorization')[0] === 'Basic BASIC_TOKEN'
        && $req['data']['username'] === 'merchant'
        && $req['data']['grantType'] === 'password');

    // Second call must NOT hit HTTP — token is cached.
    Http::fake(['*/login' => Http::response([], 500)]);
    expect(nwTokenCache()->token())->toBe('tok_nw');
});

it('forget() drops the cached token so the next call re-logs in', function () {
    Http::fake([
        '*/login' => Http::sequence()
            ->push(nwLoginResponse('tok_first'), 200)
            ->push(nwLoginResponse('tok_second'), 200),
    ]);

    $cache = nwTokenCache();
    expect($cache->token())->toBe('tok_first');
    $cache->forget();
    expect($cache->token())->toBe('tok_second');
});

it('does not share tokens between different merchants on the same base URL', function () {
    Http::fake([
        '*/login' => Http::sequence()
            ->push(nwLoginResponse('tok_a'), 200)
            ->push(nwLoginResponse('tok_b'), 200),
    ]);

    expect(nwTokenCache('merchant_a')->token())->toBe('tok_a')
        ->and(nwTokenCache('merchant_b')->token())->toBe('tok_b');
});

it('throws GatewayUnavailable on a 5xx from the login endpoint', function () {
    Http::fake(['*/login' => Http::response([], 502)]);
    nwTokenCache()->token();
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);

it('throws PaymentException when login returns a non-zero responseCode', function () {
    Http::fake(['*/login' => Http::response([
        'responseCode' => 1,
        'errCode' => '1',
        'message' => 'Invalid credentials',
        'data' => null,
    ], 200)]);
    nwTokenCache()->token();
})->throws(\Froshly\Parakit\Exceptions\PaymentException::class);
