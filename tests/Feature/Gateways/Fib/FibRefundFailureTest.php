<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\DTOs\RefundRequest;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD',
    ]);
});

it('accepts an empty successful refund response without inventing a refund id', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response([
            'status' => 'PAID',
            'amount' => ['amount' => '5000', 'currency' => 'IQD'],
        ], 200),
        '*/protected/v1/payments/*/refund' => Http::response(null, 204),
    ]);

    $response = Payment::driver('fib')->refund(new RefundRequest('pid_1', 5000));

    expect($response->success)->toBeTrue()
        ->and($response->refundId)->toBeNull()
        ->and($response->refundedAmount)->toBe(5000);
});

it('rejects a partial refund before calling the full-refund endpoint', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response([
            'status' => 'PAID',
            'amount' => ['amount' => '5000', 'currency' => 'IQD'],
        ], 200),
        '*/protected/v1/payments/*/refund' => Http::response(null, 204),
    ]);

    expect(fn () => Payment::driver('fib')->refund(new RefundRequest('pid_1', 1000)))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNotSent(fn ($req) => str_ends_with($req->url(), '/refund'));
});
