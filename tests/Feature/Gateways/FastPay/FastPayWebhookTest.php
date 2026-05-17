<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Models\PaymentTransaction;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.fastpay', [
        'driver'         => 'fastpay',
        'base_url'       => 'https://staging-pgw.fast-pay.iq',
        'store_id'       => 'STORE-1',
        'store_password' => 'secret-1',
    ]);

    PaymentTransaction::create([
        'gateway' => 'fastpay',
        'reference' => 'INV-2026-ABC',
        'gateway_transaction_id' => 'ORD12345678',
        'status' => PaymentStatus::Pending,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => 'c',
    ]);
});

it('verifies a FastPay IPN by re-fetching validate and applies the transition', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/validate_success.json'), true),
            200,
        ),
    ]);

    $this->postJson('/payments/webhooks/fastpay', [
        'order_id' => 'ORD12345678',
        'status' => 'Success',
    ])->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('rejects an IPN with no order_id as a verification failure (401)', function () {
    $this->postJson('/payments/webhooks/fastpay', ['status' => 'Success'])
        ->assertStatus(401);
});

it('rejects an IPN when the validate re-check fails (401)', function () {
    Http::fake([
        '*/api/v1/public/pgw/payment/validate' => Http::response([
            'code' => 404,
            'messages' => ['Sorry! No transaction has been found against your Order ID.'],
            'data' => null,
        ], 200),
    ]);

    $this->postJson('/payments/webhooks/fastpay', ['order_id' => 'ORD12345678'])
        ->assertStatus(401);
});
