<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Facades\Payment;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.fastpay', [
        'driver'         => 'fastpay',
        'base_url'       => 'https://staging-pgw.fast-pay.iq',
        'store_id'       => 'STORE-1',
        'store_password' => 'secret-1',
    ]);
});

it('reads a paid transaction status via the validate endpoint', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/validate_success.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('fastpay')->status('ORD12345678');

    expect($r->status)->toBe(PaymentStatus::Paid)
        ->and($r->amount)->toBe(5000)
        ->and($r->currency)->toBe(Currency::IQD);

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/payment/validate')
        && $req['order_id'] === 'ORD12345678'
        && $req['store_id'] === 'STORE-1');
});

it('treats a not-found order (code 404) as still Pending', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => Http::response([
            'code' => 404,
            'messages' => ['Sorry! No transaction has been found against your Order ID.'],
            'data' => null,
        ], 200),
    ]);

    $r = Payment::driver('fastpay')->status('ORD12345678');

    expect($r->status)->toBe(PaymentStatus::Pending);
});
