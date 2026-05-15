<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Gutian\Parakit\Gateways\ZainCash\ZainCashJwt;

beforeEach(function () {
    config()->set('parakit.gateways.zaincash', [
        'driver' => 'zaincash',
        'base_url' => 'https://test.zaincash.iq',
        'merchant_id' => 'mer_1',
        'msisdn' => '07710000000',
        'secret' => 'shared-secret-shared-secret-1234',
    ]);
});

it('posts a JWT-signed body to the local webhook URL for ZainCash', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('parakit:webhook:simulate', [
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
        return $claims['id'] === 'zc_1'
            && $claims['orderid'] === 'ord_1'
            && $claims['status'] === 'success';
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

    $this->artisan('parakit:webhook:simulate', [
        'gateway' => 'fib',
        '--status' => 'paid',
        '--transaction-id' => 'pid_1',
    ])->assertSuccessful();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'payments/webhooks/fib')
        && $req['id'] === 'pid_1'
        && $req['status'] === 'PAID');
});
