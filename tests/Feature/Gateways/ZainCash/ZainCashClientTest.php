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
