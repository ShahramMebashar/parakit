<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\SupportsStatusCheck;
use Froshly\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    config()->set('parakit.gateways.zaincash', [
        'driver' => 'zaincash',
        'base_url' => 'https://test.zaincash.iq',
        'merchant_id' => 'mer_1',
        'msisdn' => '07710000000',
        'secret' => 'shared-secret-shared-secret-1234',
    ]);
});

it('reads transaction status via /transaction/get and maps to PaymentStatus', function () {
    Http::fake([
        '*/transaction/get' => Http::response(
            ['status' => 'success', 'orderId' => 'ord_1', 'amount' => 5000],
            200,
        ),
    ]);

    $driver = Payment::driver('zaincash');
    expect($driver)->toBeInstanceOf(SupportsStatusCheck::class);

    $resp = $driver->status('zc_1');
    expect($resp->status)->toBe(PaymentStatus::Paid);
});
