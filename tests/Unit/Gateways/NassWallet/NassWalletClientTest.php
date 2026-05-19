<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\NassWallet\NassWalletClient;
use Froshly\Parakit\Gateways\NassWallet\NassWalletTokenCache;

beforeEach(fn () => Cache::flush());

function nwLoginFake(string $token = 'tok_nw'): array
{
    return [
        'responseCode' => 0,
        'errCode' => '1',
        'message' => 'Success',
        'data' => [
            'access_token' => $token,
            'accessTokenExpiry' => (string) ((time() + 3600) * 1000),
        ],
    ];
}

function nwClient(): NassWalletClient
{
    return new NassWalletClient(
        baseUrl: 'https://uatgw1.nasswallet.com',
        tokens: new NassWalletTokenCache('https://uatgw1.nasswallet.com', 'BT', 'merchant', 'pass'),
    );
}

function nwFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/NassWallet/' . $name), true);
}

it('initiates a transaction with a Bearer token and a data-wrapped body', function () {
    Http::fake([
        '*/login' => Http::response(nwLoginFake(), 200),
        '*/initTransaction' => Http::response(nwFixture('init_success.json'), 200),
    ]);

    $resp = nwClient()->initTransaction(['orderId' => '263626', 'amount' => '10.00']);

    expect($resp['data']['transactionId'])->toBe('txn_399875107092750');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/initTransaction')
        && $req->method() === 'POST'
        && $req->header('Authorization')[0] === 'Bearer tok_nw'
        && $req['data']['orderId'] === '263626');
});

it('checks a transaction by id with a data-wrapped body', function () {
    Http::fake([
        '*/login' => Http::response(nwLoginFake(), 200),
        '*/checkTransaction' => Http::response(nwFixture('check_transaction_success.json'), 200),
    ]);

    $resp = nwClient()->checkTransaction('txn_399875107092750');

    expect($resp['data']['transactionStatus'])->toBe('Success');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/checkTransaction')
        && $req['data']['transactionId'] === 'txn_399875107092750');
});

it('re-logs in once and retries when a call returns 401', function () {
    Http::fake([
        '*/login' => Http::sequence()
            ->push(nwLoginFake('stale'), 200)
            ->push(nwLoginFake('fresh'), 200),
        '*/initTransaction' => Http::sequence()
            ->push([], 401)
            ->push(nwFixture('init_success.json'), 200),
    ]);

    $resp = nwClient()->initTransaction(['orderId' => '263626']);
    expect($resp['data']['transactionId'])->toBe('txn_399875107092750');
});

it('throws GatewayUnavailable on a 5xx (retryable)', function () {
    Http::fake([
        '*/login' => Http::response(nwLoginFake(), 200),
        '*/initTransaction' => Http::response([], 503),
    ]);

    nwClient()->initTransaction(['orderId' => '263626']);
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);

it('throws a non-retryable PaymentException when responseCode is non-zero', function () {
    Http::fake([
        '*/login' => Http::response(nwLoginFake(), 200),
        '*/initTransaction' => Http::response([
            'responseCode' => 1,
            'errCode' => '1',
            'message' => 'Invalid order',
            'data' => null,
        ], 200),
    ]);

    nwClient()->initTransaction(['orderId' => '263626']);
})->throws(\Froshly\Parakit\Exceptions\PaymentException::class, 'Invalid order');
