<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Facades\Payment;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.fastpay', [
        'driver'            => 'fastpay',
        'base_url'          => 'https://staging-pgw.fast-pay.iq',
        'store_id'          => 'STORE-1',
        'store_password'    => 'secret-1',
        'refund_secret_key' => 'refund-key-1',
        'success_url'       => 'https://app.test/success',
        'cancel_url'        => 'https://app.test/cancel',
        'callback_url'      => 'https://app.test/payments/webhooks/fastpay',
    ]);
});

function fakeFastPayInitiation(): void
{
    Http::fake([
        '*/api/v1/public/pgw/payment/initiation' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/initiation_success.json'), true),
            200,
        ),
    ]);
}

it('charges via FastPay and returns the hosted-page redirect url', function () {
    fakeFastPayInitiation();

    $r = Payment::driver('fastpay')->charge(new PaymentRequest(
        reference: 'INV-2026-ABC', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->redirectUrl)->toContain('staging-pgw.fast-pay.iq/pay')
        ->and($r->gatewayTransactionId)->not->toBe('');
});

it('sends an 8-32 char alphanumeric order_id, integer bill_amount and a JSON-string cart', function () {
    fakeFastPayInitiation();

    Payment::driver('fastpay')->charge(new PaymentRequest(
        reference: 'INV-2026-ABC', amount: 5000, currency: Currency::IQD, description: 'Scarf',
    ));

    Http::assertSent(function ($req) {
        $orderId = (string) $req['order_id'];
        $cart = $req['cart'];

        return str_contains($req->url(), '/payment/initiation')
            && ctype_alnum($orderId)
            && strlen($orderId) >= 8 && strlen($orderId) <= 32
            && $req['bill_amount'] === 5000
            && $req['currency'] === 'IQD'
            && $req['store_id'] === 'STORE-1'
            && $req['store_password'] === 'secret-1'
            && $req['success_url'] === 'https://app.test/success'
            && $req['cancel_url'] === 'https://app.test/cancel'
            && $req['callback_url'] === 'https://app.test/payments/webhooks/fastpay'
            && is_string($cart)
            && json_decode($cart, true)[0]['name'] === 'Scarf'
            && json_decode($cart, true)[0]['sub_total'] === 5000;
    });
});

it('rejects a non-IQD charge — FastPay only settles IQD', function () {
    fakeFastPayInitiation();

    Payment::driver('fastpay')->charge(new PaymentRequest(
        reference: 'INV-USD', amount: 5000, currency: Currency::USD, description: 'd',
    ));
})->throws(InvalidArgumentException::class);

it('derives the same order_id for the same charge (retry-safe)', function () {
    fakeFastPayInitiation();

    $first = Payment::driver('fastpay')->charge(new PaymentRequest(
        reference: 'INV-SAME', amount: 5000, currency: Currency::IQD, description: 'd',
    ));

    Cache::flush();
    $second = Payment::driver('fastpay')->charge(new PaymentRequest(
        reference: 'INV-SAME', amount: 5000, currency: Currency::IQD, description: 'd',
    ));

    expect($first->gatewayTransactionId)->toBe($second->gatewayTransactionId);
});
