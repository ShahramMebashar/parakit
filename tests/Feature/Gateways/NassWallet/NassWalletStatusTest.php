<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Enums\Currency;
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
        'reference' => 'INV-STATUS',
        'gateway_transaction_id' => 'txn_399875107092750',
        'status' => PaymentStatus::Pending,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => 'status-test',
    ]);
});

function fakeNwLoginForStatus(): array
{
    return [
        'responseCode' => 0, 'errCode' => '1', 'message' => 'Success',
        'data' => ['access_token' => 'tok_nw', 'accessTokenExpiry' => (string) ((time() + 3600) * 1000)],
    ];
}

it('reads status from the flat checkTransaction response shape', function () {
    Http::fake([
        '*/login' => Http::response(fakeNwLoginForStatus(), 200),
        '*/checkTransaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/NassWallet/check_transaction_success.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('nasswallet')->status('txn_399875107092750');

    expect($r->status)->toBe(PaymentStatus::Paid)
        ->and($r->amount)->toBe(5000);
});

it('reads status from the rich TransactionHistoryList response shape', function () {
    Http::fake([
        '*/login' => Http::response(fakeNwLoginForStatus(), 200),
        '*/checkTransaction' => Http::response([
            'responseCode' => 0, 'errCode' => '1', 'message' => 'Success',
            'data' => [
                'TransactionId' => 'txn_399875107092750',
                'TenantId' => 'NASSWALLET',
                'TransactionHistoryList' => [
                    ['TransactionType' => 'CR', 'TransactionStatus' => 'Success', 'Amount' => '5000.00000'],
                    ['TransactionType' => 'DR', 'TransactionStatus' => 'Success', 'Amount' => '5000.00000'],
                ],
            ],
        ], 200),
    ]);

    $r = Payment::driver('nasswallet')->status('txn_399875107092750');

    expect($r->status)->toBe(PaymentStatus::Paid)
        ->and($r->amount)->toBe(5000);
});
