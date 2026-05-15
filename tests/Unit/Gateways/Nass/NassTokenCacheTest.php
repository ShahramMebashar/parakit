<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\Nass\NassTokenCache;

beforeEach(fn () => Cache::flush());

it('logs in and reads the token from data.access_token, then caches it', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response([
            'success' => true,
            'code' => 0,
            'status_code' => 200,
            'data' => ['access_token' => 'tok_nass'],
        ], 200),
    ]);

    $cache = new NassTokenCache('https://uat-gateway.nass.iq:9746', 'user', 'pass', 3000);
    expect($cache->token())->toBe('tok_nass');

    // Second call must NOT hit HTTP.
    Http::fake(['*/auth/merchant/login' => Http::response(['boom' => true], 500)]);
    expect($cache->token())->toBe('tok_nass');
});

it('falls back to a top-level access_token if data wrapper is absent', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['access_token' => 'tok_flat'], 200),
    ]);

    $cache = new NassTokenCache('https://uat-gateway.nass.iq:9746', 'user', 'pass', 3000);
    expect($cache->token())->toBe('tok_flat');
});

it('forget() drops the cached token so the next call re-logs in', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::sequence()
            ->push(['data' => ['access_token' => 'tok_first']], 200)
            ->push(['data' => ['access_token' => 'tok_second']], 200),
    ]);

    $cache = new NassTokenCache('https://uat-gateway.nass.iq:9746', 'user', 'pass', 3000);
    expect($cache->token())->toBe('tok_first');

    $cache->forget();
    expect($cache->token())->toBe('tok_second');
});

it('does not share tokens between different users on the same base URL', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::sequence()
            ->push(['data' => ['access_token' => 'tok_user_a']], 200)
            ->push(['data' => ['access_token' => 'tok_user_b']], 200),
    ]);

    $a = new NassTokenCache('https://uat-gateway.nass.iq:9746', 'user_a', 'pass', 3000);
    $b = new NassTokenCache('https://uat-gateway.nass.iq:9746', 'user_b', 'pass', 3000);

    expect($a->token())->toBe('tok_user_a')
        ->and($b->token())->toBe('tok_user_b');
});

it('throws GatewayUnavailable on a 5xx from the login endpoint', function () {
    Http::fake(['*/auth/merchant/login' => Http::response([], 502)]);
    $cache = new NassTokenCache('https://uat-gateway.nass.iq:9746', 'user', 'pass', 3000);
    $cache->token();
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);
