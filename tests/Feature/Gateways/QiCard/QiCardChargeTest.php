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
    config()->set('parakit.gateways.qicard', [
        'driver'             => 'qicard',
        'base_url'           => 'https://uat-sandbox-3ds-api.qi.iq',
        'username'           => 'paymentgatewaytest',
        'password'           => 'WHaNFE5C3qlChqNbAzH4',
        'terminal_id'        => '237984',
        'locale'             => 'en_US',
        'public_key'         => null,
        'finish_payment_url' => 'https://app.test/finish',
        'notification_url'   => 'https://app.test/payments/webhooks/qicard',
    ]);
});

function fakeQiCardCreate(): void
{
    Http::fake([
        '*/api/v1/payment' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/create_payment_success.json'), true),
            200,
        ),
    ]);
}

it('charges via QiCard and returns the hosted-form redirect url', function () {
    fakeQiCardCreate();

    $r = Payment::driver('qicard')->charge(new PaymentRequest(
        reference: 'INV-2026-QI1', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->redirectUrl)->toContain('/api/v1/payment/')
        ->and($r->gatewayTransactionId)->toBe('f2bb43a8-488a-4281-977b-5b3418fc3c67');
});

it('sends Basic Auth, X-Terminal-Id header, a ≤36 char requestId and a 2-decimal amount', function () {
    fakeQiCardCreate();

    Payment::driver('qicard')->charge(new PaymentRequest(
        reference: 'INV-2026-QI1', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    Http::assertSent(function ($req) {
        $auth = $req->header('Authorization')[0] ?? '';
        $terminal = $req->header('X-Terminal-Id')[0] ?? '';
        $body = $req->data();

        return str_ends_with($req->url(), '/api/v1/payment')
            && str_starts_with($auth, 'Basic ')
            && base64_decode(substr($auth, 6)) === 'paymentgatewaytest:WHaNFE5C3qlChqNbAzH4'
            && $terminal === '237984'
            && is_string($body['requestId']) && strlen($body['requestId']) <= 36 && $body['requestId'] !== ''
            && $body['amount'] === '5000.00'
            && $body['currency'] === 'IQD'
            && $body['locale'] === 'en_US'
            && $body['finishPaymentUrl'] === 'https://app.test/finish'
            && $body['notificationUrl'] === 'https://app.test/payments/webhooks/qicard';
    });
});

it('keeps the same requestId across HTTP retries within one charge', function () {
    // Force AbstractGateway's retry loop to fire a second performCharge by
    // answering 500 the first time and the fixture body the second. Both
    // requests must carry the same QiCard requestId so QiCard sees one
    // logical attempt, not two — otherwise it could create two payments.
    config()->set('parakit.reliability.retry.max_attempts', 2);
    config()->set('parakit.reliability.retry.base_delay_ms', 0);

    $fixture = json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/create_payment_success.json'), true);
    Http::fakeSequence()
        ->push(['error' => ['code' => 23, 'message' => 'INTERNAL']], 500)
        ->push($fixture, 200);

    Payment::driver('qicard')->charge(new PaymentRequest(
        reference: 'INV-RETRY', amount: 7500, currency: Currency::IQD, description: 'x',
    ));

    $seen = [];
    Http::assertSent(function ($req) use (&$seen) {
        if (str_ends_with($req->url(), '/api/v1/payment')) {
            $seen[] = $req->data()['requestId'];
        }
        return true;
    });

    expect($seen)->toHaveCount(2)
        ->and($seen[0])->toBe($seen[1]);
});

it('passes customerInfo when set on the PaymentRequest', function () {
    fakeQiCardCreate();

    Payment::driver('qicard')->charge(new PaymentRequest(
        reference: 'INV-C', amount: 5000, currency: Currency::IQD, description: 'x',
        customerPhone: '009647712345678',
        customerEmail: 'j@example.test',
        customerName: 'John Doe',
    ));

    Http::assertSent(function ($req) {
        $body = $req->data();
        return ($body['customerInfo']['firstName'] ?? null) === 'John Doe'
            && ($body['customerInfo']['phone'] ?? null) === '009647712345678'
            && ($body['customerInfo']['email'] ?? null) === 'j@example.test';
    });
});

it('rejects non-IQD currencies', function () {
    fakeQiCardCreate();

    expect(fn () => Payment::driver('qicard')->charge(new PaymentRequest(
        reference: 'INV-USD', amount: 100, currency: Currency::USD, description: 'x',
    )))->toThrow(InvalidArgumentException::class);
});
