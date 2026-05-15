<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shah\Parakit\Facades\Payment;
use Shah\Parakit\DTOs\RefundRequest;
use Shah\Parakit\Exceptions\GatewayUnavailableException;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD',
    ]);
});

it('throws when FIB refund returns 200 but no refundId', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/refund' => Http::response(['refundId' => null], 200),
    ]);

    Payment::driver('fib')->refund(new RefundRequest('pid_1', 5000));
})->throws(GatewayUnavailableException::class);
