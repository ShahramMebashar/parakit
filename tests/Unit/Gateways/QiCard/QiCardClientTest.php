<?php
declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Exceptions\GatewayTimeoutException;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Gateways\QiCard\QiCardApiException;
use Froshly\Parakit\Gateways\QiCard\QiCardClient;

function makeQiCardClient(): QiCardClient
{
    return new QiCardClient(
        baseUrl: 'https://uat-sandbox-3ds-api.qi.iq',
        username: 'u',
        password: 'p',
        terminalId: '237984',
    );
}

it('throws GatewayUnavailableException on 5xx (retryable)', function () {
    Http::fake([
        '*/api/v1/payment' => Http::response(['error' => ['code' => 23]], 500),
    ]);

    expect(fn () => makeQiCardClient()->createPayment(['requestId' => 'x']))
        ->toThrow(GatewayUnavailableException::class);
});

it('throws QiCardApiException with the apiCode on 4xx (non-retryable)', function () {
    Http::fake([
        '*/api/v1/payment' => Http::response([
            'error' => ['code' => 27, 'message' => 'BAD_CREDENTIALS'],
        ], 400),
    ]);

    try {
        makeQiCardClient()->createPayment(['requestId' => 'x']);
        $this->fail('expected QiCardApiException');
    } catch (QiCardApiException $e) {
        expect($e->apiCode)->toBe(27);
    }
});

it('accepts the prose-docs error shape (`description` instead of `message`)', function () {
    Http::fake([
        '*/api/v1/payment' => Http::response([
            'error' => ['code' => 27, 'description' => 'Authentication required: Incorrect credentials'],
        ], 400),
    ]);

    try {
        makeQiCardClient()->createPayment(['requestId' => 'x']);
        $this->fail('expected QiCardApiException');
    } catch (QiCardApiException $e) {
        expect($e->apiCode)->toBe(27)
            ->and($e->getMessage())->toContain('Incorrect credentials');
    }
});

it('wraps a ConnectionException as GatewayTimeoutException', function () {
    Http::fake(function () {
        throw new ConnectionException('connection refused');
    });

    expect(fn () => makeQiCardClient()->createPayment(['requestId' => 'x']))
        ->toThrow(GatewayTimeoutException::class);
});

it('returns the decoded JSON body on success', function () {
    Http::fake([
        '*/api/v1/payment' => Http::response(['paymentId' => 'pid_1', 'status' => 'CREATED'], 200),
    ]);

    expect(makeQiCardClient()->createPayment(['requestId' => 'x']))
        ->toBe(['paymentId' => 'pid_1', 'status' => 'CREATED']);
});

it('GET /status uses GET method', function () {
    Http::fake([
        '*/api/v1/payment/*/status' => Http::response(['paymentId' => 'pid_1', 'status' => 'SUCCESS'], 200),
    ]);

    makeQiCardClient()->getStatus('pid_1');

    Http::assertSent(function ($req) {
        return $req->method() === 'GET' && str_contains($req->url(), '/payment/pid_1/status');
    });
});
