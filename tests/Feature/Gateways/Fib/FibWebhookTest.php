<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Gutian\Parakit\Models\PaymentTransaction;
use Gutian\Parakit\Enums\PaymentStatus;
use Gutian\Parakit\Enums\Currency;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD', 'callback_url' => 'https://app.test/cb',
    ]);

    PaymentTransaction::create([
        'gateway' => 'fib',
        'reference' => 'ord_1',
        'gateway_transaction_id' => 'pid_1',
        'status' => PaymentStatus::Pending,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => 'c',
    ]);
});

it('verifies a FIB callback by re-fetching status and applies state transition', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response([
            'paymentId' => 'pid_1',
            'status' => 'PAID',
            'amount' => ['amount' => '5000', 'currency' => 'IQD'],
        ], 200),
    ]);

    $this->postJson('/payments/webhooks/fib', ['id' => 'pid_1', 'status' => 'PAID'])
        ->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('returns 401 when the FIB status endpoint disagrees / cannot be reached', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response([], 500),
    ]);

    $this->postJson('/payments/webhooks/fib', ['id' => 'pid_1', 'status' => 'PAID'])
        ->assertStatus(401);
});

it('returns 401 when payload lacks an id', function () {
    $this->postJson('/payments/webhooks/fib', [])->assertStatus(401);
});
