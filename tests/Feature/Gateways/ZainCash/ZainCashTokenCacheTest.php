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
