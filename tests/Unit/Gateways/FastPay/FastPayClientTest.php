<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\FastPay\FastPayClient;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Exceptions\PaymentException;

function fastPayClient(): FastPayClient
{
    return new FastPayClient(baseUrl: 'https://staging-pgw.fast-pay.iq');
}

function fastPayFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/' . $name), true);
}

it('posts to the initiation endpoint without an auth header and returns the body', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/initiation' => Http::response(fastPayFixture('initiation_success.json'), 200),
    ]);

    $resp = fastPayClient()->initiation(['store_id' => 'S1', 'order_id' => 'ORD123456']);

    expect($resp['data']['redirect_uri'])->toContain('staging-pgw.fast-pay.iq');

    Http::assertSent(fn ($req) =>
        $req->method() === 'POST'
        && str_contains($req->url(), '/api/v1/public/pgw/payment/initiation')
        && $req->hasHeader('Authorization') === false
        && $req['order_id'] === 'ORD123456');
});

it('posts to the validate endpoint', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => Http::response(fastPayFixture('validate_success.json'), 200),
    ]);

    $resp = fastPayClient()->validate(['store_id' => 'S1', 'order_id' => 'ORD123456']);

    expect($resp['data']['status'])->toBe('Success');
});

it('posts to the refund endpoint', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/refund' => Http::response(fastPayFixture('refund_success.json'), 200),
    ]);

    $resp = fastPayClient()->refund(['store_id' => 'S1', 'order_id' => 'ORD123456', 'amount' => 250]);

    expect($resp['data']['summary']['invoice_id'])->toBe('CXMNPZQ030');
});

it('throws GatewayUnavailable on a 5xx (retryable)', function () {
    Http::fake([
        '*/payment/initiation' => Http::response('boom', 503),
    ]);

    fastPayClient()->initiation(['order_id' => 'ORD123456']);
})->throws(GatewayUnavailableException::class);

it('throws a non-retryable PaymentException when the body code is 422', function () {
    Http::fake([
        '*/payment/initiation' => Http::response([
            'code' => 422,
            'messages' => ['Sorry! The Store ID and Store Password combination is wrong.'],
            'data' => null,
        ], 200),
    ]);

    fastPayClient()->initiation(['order_id' => 'ORD123456']);
})->throws(PaymentException::class, 'Store ID and Store Password');

it('throws a non-retryable PaymentException when the body code is 404', function () {
    Http::fake([
        '*/payment/validate' => Http::response([
            'code' => 404,
            'messages' => ['Sorry! No transaction has been found against your Order ID.'],
            'data' => null,
        ], 200),
    ]);

    fastPayClient()->validate(['order_id' => 'ORD123456']);
})->throws(PaymentException::class, 'No transaction has been found');
