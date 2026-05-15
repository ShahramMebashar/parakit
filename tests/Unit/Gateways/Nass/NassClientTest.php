<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\Nass\NassClient;
use Froshly\Parakit\Gateways\Nass\NassTokenCache;

beforeEach(fn () => Cache::flush());

function nassClient(): NassClient
{
    return new NassClient(
        baseUrl: 'https://uat-gateway.nass.iq:9746',
        tokens: new NassTokenCache('https://uat-gateway.nass.iq:9746', 'user', 'pass', 3000),
    );
}

it('creates a transaction with a bearer token', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/init_success.json'), true),
            201,
        ),
    ]);

    $resp = nassClient()->initTransaction([
        'orderId' => '4471920038',
        'amount' => '5000',
        'currency' => '368',
        'transactionType' => 1,
    ]);

    expect($resp['data']['url'])->toContain('3dsecure.nass.iq');

    Http::assertSent(fn ($req) =>
        $req->method() === 'POST'
        && str_contains($req->url(), '/transaction')
        && $req->header('Authorization')[0] === 'Bearer tok'
        && $req['orderId'] === '4471920038');
});

it('checks status by orderId via GET', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction/4471920038/checkStatus' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/check_status_approved.json'), true),
            200,
        ),
    ]);

    $resp = nassClient()->checkStatus('4471920038');
    expect($resp['data']['responseCode'])->toBe('00');

    Http::assertSent(fn ($req) =>
        $req->method() === 'GET'
        && str_contains($req->url(), '/transaction/4471920038/checkStatus'));
});

it('re-logs in once and retries when a call returns 401', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::sequence()
            ->push(['data' => ['access_token' => 'stale']], 200)
            ->push(['data' => ['access_token' => 'fresh']], 200),
        '*/transaction' => Http::sequence()
            ->push([], 401)
            ->push(json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/init_success.json'), true), 201),
    ]);

    $resp = nassClient()->initTransaction(['orderId' => '4471920038']);
    expect($resp['data']['url'])->toContain('3dsecure.nass.iq');
});

it('throws GatewayUnavailable on a 5xx (retryable)', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction' => Http::response([], 503),
    ]);

    nassClient()->initTransaction(['orderId' => '4471920038']);
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);

it('throws a non-retryable PaymentException on a 409 duplicate order id', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction' => Http::response([
            'success' => false,
            'code' => 1,
            'status_code' => 409,
            'data' => ['message' => 'Transaction with this Order ID already exists'],
        ], 409),
    ]);

    nassClient()->initTransaction(['orderId' => '4471920038']);
})->throws(
    \Froshly\Parakit\Exceptions\PaymentException::class,
    'Transaction with this Order ID already exists',
);
