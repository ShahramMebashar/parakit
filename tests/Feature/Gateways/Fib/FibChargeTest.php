<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Gutian\Parakit\Facades\Payment;
use Gutian\Parakit\DTOs\PaymentRequest;
use Gutian\Parakit\Enums\Currency;
use Gutian\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'currency' => 'IQD',
        'callback_url' => 'https://app.test/cb',
    ]);
});

it('charges via FIB and returns QR/deep-link/readable code', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments' => Http::response([
            'paymentId' => 'pid_1',
            'readableCode' => 'AB12-CD34',
            'personalAppLink' => 'fib://pay?id=pid_1',
            'qrCode' => 'data:image/png;base64,XYZ',
            'validUntil' => '2026-05-14T11:00:00Z',
        ], 200),
    ]);

    $r = Payment::driver('fib')->charge(new PaymentRequest(
        reference: 'ord_1', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->gatewayTransactionId)->toBe('pid_1')
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->readableCode)->toBe('AB12-CD34')
        ->and($r->qrCode)->toBe('data:image/png;base64,XYZ')
        ->and($r->deepLink)->toBe('fib://pay?id=pid_1');

    // FIB expects monetaryValue.amount as a decimal string in MAJOR units.
    // For IQD (factor 1) that equals the minor-unit integer; the test still
    // protects against a regression that would ship cents as dollars.
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/protected/v1/payments')
        && $req['monetaryValue']['amount'] === '5000'
        && $req['monetaryValue']['currency'] === 'IQD');
});

it('converts minor units to major-unit decimal when charging in USD', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments' => Http::response(['paymentId' => 'pid_usd'], 200),
    ]);

    Payment::driver('fib')->charge(new PaymentRequest(
        reference: 'ord_usd', amount: 5000, currency: Currency::USD, description: 'Order USD',
    ));

    // 5000 minor USD == $50.00 major. Without conversion FIB would charge $5000.
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/protected/v1/payments')
        && $req['monetaryValue']['amount'] === '50.00'
        && $req['monetaryValue']['currency'] === 'USD');
});
