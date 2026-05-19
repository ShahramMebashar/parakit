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
    config()->set('parakit.gateways.nasswallet', [
        'driver'      => 'nasswallet',
        'base_url'    => 'https://uatgw1.nasswallet.com/payment/transaction',
        'portal_url'  => 'https://uatcheckout1.nasswallet.com',
        'basic_token' => 'BASIC_TOKEN',
        'username'    => '7500077974',
        'password'    => 'Nass@2020',
    ]);

    PaymentTransaction::create([
        'gateway' => 'nasswallet',
        'reference' => 'INV-2026-ABC',
        'gateway_transaction_id' => 'txn_399875107092750',
        'status' => PaymentStatus::Pending,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => 'c',
    ]);
});

function fakeNwLoginForWebhook(): array
{
    return [
        'responseCode' => 0, 'errCode' => '1', 'message' => 'Success',
        'data' => ['access_token' => 'tok_nw', 'accessTokenExpiry' => (string) ((time() + 3600) * 1000)],
    ];
}

it('verifies a NassWallet callback via checkTransaction and applies the transition', function () {
    Http::fake([
        '*/login' => Http::response(fakeNwLoginForWebhook(), 200),
        '*/checkTransaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/NassWallet/check_transaction_success.json'), true),
            200,
        ),
    ]);

    $this->postJson('/payments/webhooks/nasswallet/callback', [
        'data' => [
            'orderId' => '263626',
            'transId' => 'txn_1605680757506612877',
            'InitTransactionId' => 'txn_399875107092750',
            'currency' => 'IQD',
            'amount' => '5000.00',
            'transactionStatus' => 'Success',
            'transactionTime' => '1599306060180',
        ],
    ])->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('rejects a callback with no InitTransactionId as a verification failure (401)', function () {
    $this->postJson('/payments/webhooks/nasswallet/callback', [
        'data' => ['orderId' => '263626', 'transactionStatus' => 'Success'],
    ])->assertStatus(401);
});

it('rejects a callback when the checkTransaction re-verify fails (401)', function () {
    Http::fake([
        '*/login' => Http::response(fakeNwLoginForWebhook(), 200),
        '*/checkTransaction' => Http::response([
            'responseCode' => 1, 'errCode' => '1', 'message' => 'Not found', 'data' => null,
        ], 200),
    ]);

    $this->postJson('/payments/webhooks/nasswallet/callback', [
        'data' => ['InitTransactionId' => 'txn_399875107092750'],
    ])->assertStatus(401);
});
