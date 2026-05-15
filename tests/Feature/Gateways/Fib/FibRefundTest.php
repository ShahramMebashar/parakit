<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Gutian\Parakit\Facades\Payment;
use Gutian\Parakit\DTOs\RefundRequest;
use Gutian\Parakit\Contracts\SupportsRefund;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD',
    ]);
});

it('refunds a transaction and reports success', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/refund' => Http::response(['refundId' => 'rf_1'], 200),
    ]);

    $driver = Payment::driver('fib');
    expect($driver)->toBeInstanceOf(SupportsRefund::class);

    $resp = $driver->refund(new RefundRequest('pid_1', 5000));
    expect($resp->success)->toBeTrue()->and($resp->refundId)->toBe('rf_1');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/protected/v1/payments/pid_1/refund')
        && $req->method() === 'POST');
});
