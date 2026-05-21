<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\Enums\PaymentErrorCode;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Support\IdempotencyKey;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.qicard', [
        'driver'      => 'qicard',
        'base_url'    => 'https://uat-sandbox-3ds-api.qi.iq',
        'username'    => 'u',
        'password'    => 'p',
        'terminal_id' => '237984',
    ]);
});

it('refunds a payment and returns the refundId', function () {
    Http::fake([
        '*/api/v1/payment/*/refund' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/refund_success.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('qicard')->refund(new RefundRequest(
        transactionId: 'f2bb43a8-488a-4281-977b-5b3418fc3c67',
        amount: 5000,
        reason: 'Customer requested refund',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->refundId)->toBe('37b85e60-e7a6-4abb-9466-703472fb83b9')
        ->and($r->refundedAmount)->toBe(5000);
});

it('sends a 2-decimal amount and the optional message field', function () {
    Http::fake([
        '*/api/v1/payment/*/refund' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/refund_success.json'), true),
            200,
        ),
    ]);

    Payment::driver('qicard')->refund(new RefundRequest(
        transactionId: 'pid_1', amount: 7500, reason: 'change-of-mind',
    ));

    Http::assertSent(function ($req) {
        if (!str_contains($req->url(), '/refund')) {
            return false;
        }
        $body = $req->data();
        return $body['amount'] === '7500.00'
            && ($body['message'] ?? null) === 'change-of-mind'
            && is_string($body['requestId']) && $body['requestId'] !== '';
    });
});

it('returns a failed RefundResponse when QiCard answers status FAILED', function () {
    Http::fake([
        '*/api/v1/payment/*/refund' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/refund_failed.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('qicard')->refund(new RefundRequest(
        transactionId: 'pid_1', amount: 5000,
    ));

    expect($r->success)->toBeFalse()
        ->and($r->refundedAmount)->toBe(0)
        ->and($r->error)->not->toBeNull()
        ->and($r->error->rawCode)->toBe('82');
});

it('persists the RefundResponse and skips the gateway call on retry with the same idempotencyKey', function () {
    Http::fake([
        '*/api/v1/payment/*/refund' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/refund_success.json'), true),
            200,
        ),
    ]);

    $req = new RefundRequest(
        transactionId: 'pid_1', amount: 5000, idempotencyKey: 'refund-order-1',
    );

    $first = Payment::driver('qicard')->refund($req);
    Cache::flush();
    $second = Payment::driver('qicard')->refund($req);

    expect($first->success)->toBeTrue()
        ->and($second->success)->toBeTrue()
        ->and($second->refundId)->toBe($first->refundId);

    $calls = 0;
    Http::assertSent(function ($req) use (&$calls) {
        if (str_contains($req->url(), '/refund')) {
            $calls++;
        }
        return true;
    });
    expect($calls)->toBe(1);
});

it('derives requestId from the idempotencyKey so QiCard sees the same id on retries', function () {
    Http::fake([
        '*/api/v1/payment/*/refund' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/refund_success.json'), true),
            200,
        ),
    ]);

    Payment::driver('qicard')->refund(new RefundRequest(
        transactionId: 'pid_1', amount: 5000, idempotencyKey: 'refund-order-2',
    ));

    $expected = substr(
        IdempotencyKey::forGatewayOperation('qicard', 'refund', 'refund-order-2'),
        0,
        36,
    );
    Http::assertSent(fn ($req) =>
        ! str_contains($req->url(), '/refund') || (string) $req['requestId'] === $expected,
    );
});

it('maps QiCard error code 20 (REFUND_ERROR) to a failed RefundResponse', function () {
    Http::fake([
        '*/api/v1/payment/*/refund' => Http::response([
            'error' => ['code' => 20, 'message' => 'REFUND_ERROR'],
        ], 400),
    ]);

    $r = Payment::driver('qicard')->refund(new RefundRequest(
        transactionId: 'pid_1', amount: 5000,
    ));

    expect($r->success)->toBeFalse()
        ->and($r->error)->not->toBeNull()
        ->and($r->error->code)->toBe(PaymentErrorCode::Unknown)
        ->and($r->error->rawCode)->toBe('20');
});
