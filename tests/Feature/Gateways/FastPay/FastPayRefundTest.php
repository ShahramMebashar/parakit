<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\Enums\PaymentErrorCode;
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
    ]);
});

function fakeFastPayValidate(): mixed
{
    return Http::response(
        json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/validate_success.json'), true),
        200,
    );
}

it('refunds by looking up the payer msisdn via validate, then calling refund', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => fakeFastPayValidate(),
        '*/api/v1/public/pgw/payment/refund' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/refund_success.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('fastpay')->refund(new RefundRequest(
        transactionId: 'ORD12345678', amount: 5000,
    ));

    expect($r->success)->toBeTrue()
        ->and($r->refundId)->toBe('CXMNPZQ030')
        ->and($r->refundedAmount)->toBe(5000);

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/payment/refund')
        && $req['order_id'] === 'ORD12345678'
        && $req['amount'] === 5000
        && $req['refund_secret_key'] === 'refund-key-1'
        && $req['msisdn'] === '+9641000000004');
});

it('returns a failed RefundResponse when FastPay reports the transaction already refunded', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => fakeFastPayValidate(),
        '*/api/v1/public/pgw/payment/refund' => Http::response([
            'code' => 422,
            'messages' => ['The transaction is already refunded'],
            'data' => null,
        ], 200),
    ]);

    $r = Payment::driver('fastpay')->refund(new RefundRequest(
        transactionId: 'ORD12345678', amount: 5000,
    ));

    expect($r->success)->toBeFalse()
        ->and($r->refundId)->toBeNull()
        ->and($r->error?->code)->toBe(PaymentErrorCode::DuplicateTransaction);
});
