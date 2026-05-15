<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD', 'callback_url' => 'https://app.test/cb',
    ]);
});

it('reads status PAID for a given payment id', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response([
            'paymentId' => 'pid_1',
            'status' => 'PAID',
            'amount' => ['amount' => '5000', 'currency' => 'IQD'],
        ], 200),
    ]);

    $r = Payment::driver('fib')->status('pid_1');
    expect($r->status)->toBe(PaymentStatus::Paid)
        ->and($r->amount)->toBe(5000)
        ->and($r->currency)->toBe(Currency::IQD);
});
