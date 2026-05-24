<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Gateways\ZainCash\ZainCashJwt;

beforeEach(function () {
    config()->set('parakit.gateways.zaincash', [
        'driver' => 'zaincash',
        'base_url' => 'https://pg-api-uat.zaincash.iq',
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'api_key' => 'shared-secret-shared-secret-1234',
    ]);
});

it('posts a JWT-signed body to the local webhook URL for ZainCash', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('parakit:webhooks:simulate', [
        'gateway' => 'zaincash',
        '--status' => 'paid',
        '--reference' => 'ord_1',
        '--transaction-id' => 'zc_1',
    ])->assertSuccessful();

    Http::assertSent(function ($req) {
        if (!str_contains($req->url(), 'payments/webhooks/zaincash')) {
            return false;
        }
        $token = $req['token'] ?? null;
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return false;
        }
        $claims = (new ZainCashJwt('shared-secret-shared-secret-1234'))->decode($token);
        return $claims['data']['transactionId'] === 'zc_1'
            && $claims['data']['orderId'] === 'ord_1'
            && $claims['data']['currentStatus'] === 'SUCCESS';
    });
});

it('posts an unsigned id/status body for FIB (FIB callbacks are unsigned)', function () {
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD', 'callback_url' => 'https://app.test/cb',
    ]);
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('parakit:webhooks:simulate', [
        'gateway' => 'fib',
        '--status' => 'paid',
        '--transaction-id' => 'pid_1',
    ])->assertSuccessful();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'payments/webhooks/fib')
        && $req['id'] === 'pid_1'
        && $req['status'] === 'PAID');
});

it('posts an orderId body to the NassPay webhook route', function () {
    config()->set('parakit.gateways.nass', [
        'driver' => 'nass',
        'base_url' => 'https://uat-gateway.nass.iq:9746',
        'username' => 'u', 'password' => 'p',
    ]);
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('parakit:webhooks:simulate', [
        'gateway' => 'nass',
        '--transaction-id' => 'nass_1',
    ])->assertSuccessful();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'payments/webhooks/nass')
        && $req['orderId'] === 'nass_1');
});

it('posts to the NassWallet /callback route with a nested data envelope', function () {
    config()->set('parakit.gateways.nasswallet', [
        'driver' => 'nasswallet',
        'base_url' => 'https://uatgw1.nasswallet.com/payment/transaction',
        'portal_url' => 'https://uatcheckout1.nasswallet.com',
        'basic_token' => 'BASIC_TOKEN',
        'username' => '7500077974', 'password' => 'secret',
    ]);
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('parakit:webhooks:simulate', [
        'gateway' => 'nasswallet',
        '--transaction-id' => 'nw_1',
    ])->assertSuccessful();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'payments/webhooks/nasswallet/callback')
        && $req['data']['InitTransactionId'] === 'nw_1');
});

it('posts an order_id body to the FastPay webhook route', function () {
    config()->set('parakit.gateways.fastpay', [
        'driver' => 'fastpay',
        'base_url' => 'https://staging-pgw.fast-pay.iq',
        'store_id' => 'STORE-1', 'store_password' => 'secret-1',
    ]);
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('parakit:webhooks:simulate', [
        'gateway' => 'fastpay',
        '--transaction-id' => 'fp_1',
    ])->assertSuccessful();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'payments/webhooks/fastpay')
        && $req['order_id'] === 'fp_1');
});
