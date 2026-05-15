<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Shah\Parakit\Gateways\Fib\FibClient;
use Shah\Parakit\Gateways\Fib\FibTokenCache;

beforeEach(fn () => Cache::flush());

it('creates a charge with bearer token and decimal monetaryValue', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Fib/charge_success.json'), true),
            200,
        ),
    ]);

    $client = new FibClient(
        baseUrl: 'https://fib.stage.fib.iq',
        tokens: new FibTokenCache('https://fib.stage.fib.iq', 'cid', 'csecret'),
    );

    $resp = $client->createCharge([
        'amount' => 5000,
        'currency' => 'IQD',
        'callback' => 'https://app.test/cb',
        'description' => 'Order 1',
    ]);

    expect($resp['paymentId'])->toBeString();

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && str_contains($req->url(), '/protected/v1/payments')
            && $req->header('Authorization')[0] === 'Bearer tok'
            && $req['monetaryValue']['amount'] === '5000'
            && $req['monetaryValue']['currency'] === 'IQD';
    });
});

it('fetches status by id', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Fib/status_paid.json'), true),
            200,
        ),
    ]);

    $client = new FibClient(
        baseUrl: 'https://fib.stage.fib.iq',
        tokens: new FibTokenCache('https://fib.stage.fib.iq', 'cid', 'csecret'),
    );

    $resp = $client->fetchStatus('f1f9d4c7-7c4f-4dc5-92c0-1234567890ab');
    expect($resp['status'])->toBe('PAID');
});
