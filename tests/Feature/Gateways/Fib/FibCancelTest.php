<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\SupportsCancel;
use Froshly\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD',
    ]);
});

it('cancels an unpaid payment and returns the post-cancel status', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 60]),
        '*/protected/v1/payments/pid_1/cancel' => Http::response([], 200),
        '*/protected/v1/payments/pid_1/status' => Http::response([
            'paymentId' => 'pid_1',
            'status' => 'CANCELLED',
            'amount' => ['amount' => '5000', 'currency' => 'IQD'],
        ], 200),
    ]);

    $driver = Payment::driver('fib');
    expect($driver)->toBeInstanceOf(SupportsCancel::class);

    $resp = $driver->cancel('pid_1');

    expect($resp->status)->toBe(PaymentStatus::Cancelled)
        ->and($resp->gatewayTransactionId)->toBe('pid_1');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/protected/v1/payments/pid_1/cancel')
        && $req->method() === 'POST');
});

it('throws GatewayUnavailableException when cancel fails', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 60]),
        '*/protected/v1/payments/*/cancel' => Http::response('already paid', 409),
    ]);

    Payment::driver('fib')->cancel('pid_1');
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);
