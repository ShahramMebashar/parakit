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
    config()->set('parakit.gateways.nass', [
        'driver' => 'nass',
        'base_url' => 'https://uat-gateway.nass.iq:9746',
        'username' => 'user',
        'password' => 'pass',
        'token_ttl' => 3000,
        'transaction_type' => 1,
        'callback_url' => 'https://app.test/payments/webhooks/nass',
        'return_url' => 'https://app.test/return',
    ]);
});

it('charges via NassPay and returns the 3DS redirect url', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/init_success.json'), true),
            201,
        ),
    ]);

    $r = Payment::driver('nass')->charge(new PaymentRequest(
        reference: 'INV-2026-ABC', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->redirectUrl)->toContain('3dsecure.nass.iq')
        ->and($r->gatewayTransactionId)->not->toBe('')
        ->and(ctype_digit((string) $r->gatewayTransactionId))->toBeTrue();
});

it('sends a numeric orderId, major-unit amount and ISO numeric currency', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/init_success.json'), true),
            201,
        ),
    ]);

    Payment::driver('nass')->charge(new PaymentRequest(
        reference: 'INV-2026-ABC', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction')
        && ctype_digit((string) $req['orderId'])
        && $req['amount'] === '5000'
        && $req['currency'] === '368'
        && $req['transactionType'] === 1
        && $req['backRef'] === 'https://app.test/return'
        && $req['notifyUrl'] === 'https://app.test/payments/webhooks/nass');
});

it('derives the same orderId for the same charge (retry-safe)', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/init_success.json'), true),
            201,
        ),
    ]);

    $first = Payment::driver('nass')->charge(new PaymentRequest(
        reference: 'INV-SAME', amount: 5000, currency: Currency::IQD, description: 'd',
    ));

    // A fresh charge() with the same inputs derives the same idempotency key,
    // hence the same NassPay orderId.
    Cache::flush();
    $second = Payment::driver('nass')->charge(new PaymentRequest(
        reference: 'INV-SAME', amount: 5000, currency: Currency::IQD, description: 'd',
    ));

    expect($first->gatewayTransactionId)->toBe($second->gatewayTransactionId);
});
