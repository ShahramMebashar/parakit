<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\SupportsRefund;
use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Models\PaymentTransaction;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.zaincash', [
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => 'shared-secret-shared-secret-1234',
    ]);

    PaymentTransaction::create([
        'gateway' => 'zaincash',
        'reference' => 'ord_1',
        'gateway_transaction_id' => 'zc_1',
        'status' => PaymentStatus::Paid,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => 'c',
    ]);
});

it('reverses a transaction in full and returns the reversal reference', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/reverse' => Http::response([
            'status' => 'COMPLETED',
            'reversalReferenceId' => 'rev_1',
            'amount' => 5000,
        ], 200),
    ]);

    $driver = Payment::driver('zaincash');
    expect($driver)->toBeInstanceOf(SupportsRefund::class);

    $resp = $driver->refund(new RefundRequest(
        transactionId: 'zc_1',
        amount: 5000,
        reason: 'duplicate order',
    ));

    expect($resp->success)->toBeTrue()
        ->and($resp->refundId)->toBe('rev_1')
        ->and($resp->refundedAmount)->toBe(5000);

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/reverse')
        && $req['transactionId'] === 'zc_1'
        && $req['reason'] === 'duplicate order');
});

it('rejects a partial refund — v2 reverse is full-only', function () {
    Http::fake(['*' => Http::response([], 200)]);

    Payment::driver('zaincash')->refund(new RefundRequest(
        transactionId: 'zc_1',
        amount: 2000,
        reason: 'partial',
    ));
})->throws(InvalidArgumentException::class);

it('treats a non-COMPLETED reverse response as a gateway failure', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/reverse' => Http::response(['status' => 'PENDING'], 200),
    ]);

    Payment::driver('zaincash')->refund(new RefundRequest(
        transactionId: 'zc_1',
        amount: 5000,
    ));
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);
