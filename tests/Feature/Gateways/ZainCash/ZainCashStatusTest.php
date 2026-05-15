<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.zaincash', [
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => 'shared-secret-shared-secret-1234',
    ]);
});

it('reads transaction status via the v2 inquiry endpoint', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/inquiry/*' => Http::response([
            'status' => 'SUCCESS',
            'transactionDetails' => [
                'transactionId' => 'zc_1',
                'orderId' => 'ord_1',
                'amount' => ['currency' => 'IQD', 'value' => 5000],
            ],
        ], 200),
    ]);

    $driver = Payment::driver('zaincash');
    expect($driver)->toBeInstanceOf(SupportsStatusCheck::class);

    $resp = $driver->status('zc_1');

    expect($resp->status)->toBe(PaymentStatus::Paid)
        ->and($resp->gatewayTransactionId)->toBe('zc_1')
        ->and($resp->reference)->toBe('ord_1')
        ->and($resp->amount)->toBe(5000);

    Http::assertSent(fn ($req) =>
        $req->method() === 'GET'
        && str_contains($req->url(), '/transaction/inquiry/zc_1'));
});

it('maps an OTP_SENT inquiry to Pending', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/inquiry/*' => Http::response(['status' => 'OTP_SENT'], 200),
    ]);

    expect(Payment::driver('zaincash')->status('zc_1')->status)->toBe(PaymentStatus::Pending);
});
