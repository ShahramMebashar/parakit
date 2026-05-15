<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shah\Parakit\Gateways\Fib\FibTokenCache;

beforeEach(fn () => Cache::flush());

it('fetches a token via client-credentials and caches it for expires_in - 60 seconds', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response([
            'access_token' => 'tok_abc',
            'expires_in' => 600,
        ], 200),
    ]);

    $cache = new FibTokenCache('https://fib.stage.fib.iq', 'cid', 'csecret');
    expect($cache->token())->toBe('tok_abc');

    // second call must NOT hit HTTP
    Http::fake(['*/protocol/openid-connect/token' => Http::response(['boom' => true], 500)]);
    expect($cache->token())->toBe('tok_abc');
});

it('throws GatewayUnavailable on token endpoint 5xx', function () {
    Http::fake(['*/protocol/openid-connect/token' => Http::response([], 502)]);
    $cache = new FibTokenCache('https://fib.stage.fib.iq', 'cid', 'csecret');
    $cache->token();
})->throws(\Shah\Parakit\Exceptions\GatewayUnavailableException::class);
