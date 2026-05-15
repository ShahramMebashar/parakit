<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\Fib\FibTokenCache;

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

it('does not share tokens between caches with different base URLs', function () {
    Http::fake([
        'https://fib1.example.com/protocol/openid-connect/token' => Http::response([
            'access_token' => 'tok_fib1',
            'expires_in' => 600,
        ], 200),
        'https://fib2.example.com/protocol/openid-connect/token' => Http::response([
            'access_token' => 'tok_fib2',
            'expires_in' => 600,
        ], 200),
    ]);

    $cache1 = new FibTokenCache('https://fib1.example.com', 'cid', 'secret');
    $cache2 = new FibTokenCache('https://fib2.example.com', 'cid', 'secret');

    expect($cache1->token())->toBe('tok_fib1')
        ->and($cache2->token())->toBe('tok_fib2');
});

it('does not share tokens between caches with the same base URL but different client IDs', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::sequence()
            ->push(['access_token' => 'tok_client_a', 'expires_in' => 600])
            ->push(['access_token' => 'tok_client_b', 'expires_in' => 600]),
    ]);

    $cache1 = new FibTokenCache('https://fib.stage.fib.iq', 'client_a', 'secret_a');
    $cache2 = new FibTokenCache('https://fib.stage.fib.iq', 'client_b', 'secret_b');

    expect($cache1->token())->toBe('tok_client_a')
        ->and($cache2->token())->toBe('tok_client_b');
});

it('throws GatewayUnavailable on token endpoint 5xx', function () {
    Http::fake(['*/protocol/openid-connect/token' => Http::response([], 502)]);
    $cache = new FibTokenCache('https://fib.stage.fib.iq', 'cid', 'csecret');
    $cache->token();
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);
