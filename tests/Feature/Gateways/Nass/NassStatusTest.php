<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.nass', [
        'driver' => 'nass',
        'base_url' => 'https://uat-gateway.nass.iq:9746',
        'username' => 'user',
        'password' => 'pass',
        'token_ttl' => 3000,
    ]);
});

it('reads an approved status for a given orderId', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction/*/checkStatus' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/check_status_approved.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('nass')->status('4471920038');

    expect($r->status)->toBe(PaymentStatus::Paid)
        ->and($r->amount)->toBe(10)
        ->and($r->currency)->toBe(Currency::IQD)
        ->and($r->gatewayTransactionId)->toBe('4471920038');
});

it('maps a cancelled responseCode to Cancelled', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction/*/checkStatus' => Http::response([
            'success' => true,
            'code' => 0,
            'status_code' => 200,
            'data' => ['responseCode' => '-25', 'amount' => '10', 'currency' => '368'],
        ], 200),
    ]);

    $r = Payment::driver('nass')->status('4471920038');
    expect($r->status)->toBe(PaymentStatus::Cancelled);
});
