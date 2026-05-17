<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;

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
        && $req['monetaryValue']['currency'] === 'IQD'
        && $req['statusCallbackUrl'] === 'https://app.test/cb');
});

it('sends optional charge fields, with metadata overriding config', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 60]),
        '*/protected/v1/payments' => Http::response(['paymentId' => 'pid_dur'], 200),
    ]);

    config()->set('parakit.gateways.fib.refundable_for', 'P7D');
    config()->set('parakit.gateways.fib.expires_in', 'PT1H');
    config()->set('parakit.gateways.fib.category', 'ECOMMERCE');

    Payment::driver('fib')->charge(new PaymentRequest(
        reference: 'ord_dur', amount: 5000, currency: Currency::IQD, description: 'd',
        returnUrl: 'fibapp://done',
        metadata: ['expires_in' => 'PT30M'],
    ));

    // refundableFor/category fall back to config; expiresIn is overridden by
    // metadata; redirectUri comes from the request returnUrl.
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/protected/v1/payments')
        && $req['refundableFor'] === 'P7D'
        && $req['expiresIn'] === 'PT30M'
        && $req['category'] === 'ECOMMERCE'
        && $req['redirectUri'] === 'fibapp://done');
});

it('truncates the description to FIB\'s 50-character limit', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 60]),
        '*/protected/v1/payments' => Http::response(['paymentId' => 'pid_desc'], 200),
    ]);

    Payment::driver('fib')->charge(new PaymentRequest(
        reference: 'ord_desc', amount: 5000, currency: Currency::IQD,
        description: str_repeat('x', 80),
    ));

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/protected/v1/payments')
        && strlen($req['description']) === 50);
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
