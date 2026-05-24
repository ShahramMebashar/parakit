<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\Enums\PaymentErrorCode;
use Froshly\Parakit\Exceptions\GatewayUnavailableException;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Models\PaymentRefund;
use Froshly\Parakit\Support\IdempotencyKey;

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

it('persists the RefundResponse and skips the gateway on retry with the same idempotencyKey', function () {
    $refundBody = json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/refund_success.json'), true);
    $refundBody['data']['access_token'] = 'tok_refund_secret';
    $refundBody['data']['redirect_uri'] = 'https://gateway.test/refund?token=tok_refund_url&id=rf_1';

    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => fakeFastPayValidate(),
        '*/api/v1/public/pgw/payment/refund' => Http::response($refundBody, 200),
    ]);

    $req = new RefundRequest(
        transactionId: 'ORD12345678', amount: 5000, idempotencyKey: 'rfk-1',
    );

    $first = Payment::driver('fastpay')->refund($req);
    Cache::flush();
    $second = Payment::driver('fastpay')->refund($req);

    expect($first->success)->toBeTrue()
        ->and($second->refundId)->toBe($first->refundId)
        ->and(PaymentRefund::query()->count())->toBe(1)
        ->and(PaymentRefund::first()->idempotency_key)->toBe(
            IdempotencyKey::forGatewayOperation('fastpay', 'refund', 'rfk-1'),
        );

    $storedBlob = json_encode(PaymentRefund::first()->response, JSON_THROW_ON_ERROR);
    expect($first->raw['data']['access_token'])->toBe('tok_refund_secret')
        ->and($storedBlob)->not->toContain('tok_refund_secret')
        ->and($storedBlob)->not->toContain('tok_refund_url')
        ->and($storedBlob)->toContain('[REDACTED]');

    $refunds = 0;
    Http::assertSent(function ($req) use (&$refunds) {
        if (str_contains($req->url(), '/payment/refund')) {
            $refunds++;
        }
        return true;
    });
    expect($refunds)->toBe(1);
});

it('fails closed when a refund idempotency key is already pending', function () {
    PaymentRefund::create([
        'gateway' => 'fastpay',
        'idempotency_key' => IdempotencyKey::forGatewayOperation('fastpay', 'refund', 'rfk-pending'),
        'gateway_transaction_id' => 'ORD12345678',
        'amount' => 5000,
        'status' => 'pending',
    ]);

    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => fakeFastPayValidate(),
        '*/api/v1/public/pgw/payment/refund' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/refund_success.json'), true),
            200,
        ),
    ]);

    $req = new RefundRequest(
        transactionId: 'ORD12345678', amount: 5000, idempotencyKey: 'rfk-pending',
    );

    expect(fn () => Payment::driver('fastpay')->refund($req))
        ->toThrow(GatewayUnavailableException::class);

    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/payment/refund'));
});

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

it('fails the refund when FastPay returns no payer mobile number', function () {
    $validateBody = json_decode(
        file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/validate_success.json'),
        true,
    );
    $validateBody['data']['customer_mobile_number'] = '';

    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => Http::response($validateBody, 200),
        '*/api/v1/public/pgw/payment/refund' => Http::response(['code' => 200], 200),
    ]);

    $r = Payment::driver('fastpay')->refund(new RefundRequest(
        transactionId: 'ORD12345678', amount: 5000,
    ));

    expect($r->success)->toBeFalse();
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/payment/refund'));
});

it('rejects a refund larger than the original received amount', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => fakeFastPayValidate(),
        '*/api/v1/public/pgw/payment/refund' => Http::response(['code' => 200], 200),
    ]);

    $r = Payment::driver('fastpay')->refund(new RefundRequest(
        transactionId: 'ORD12345678', amount: 9000,
    ));

    expect($r->success)->toBeFalse()
        ->and($r->error?->code)->toBe(PaymentErrorCode::InvalidAmount);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/payment/refund'));
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
