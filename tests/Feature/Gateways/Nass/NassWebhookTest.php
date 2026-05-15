<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.nass', [
        'driver' => 'nass',
        'base_url' => 'https://uat-gateway.nass.iq:9746',
        'username' => 'user',
        'password' => 'pass',
        'token_ttl' => 3000,
    ]);

    PaymentTransaction::create([
        'gateway' => 'nass',
        'reference' => 'INV-2026-ABC',
        'gateway_transaction_id' => '4471920038',
        'status' => PaymentStatus::Pending,
        'amount' => 10,
        'currency' => Currency::IQD,
        'correlation_id' => 'c',
    ]);
});

it('verifies a NassPay callback by re-fetching status and applies the transition', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction/4471920038/checkStatus' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/Nass/check_status_approved.json'), true),
            200,
        ),
    ]);

    $this->postJson('/payments/webhooks/nass', ['orderId' => '4471920038', 'responseCode' => '00'])
        ->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('returns 401 when the checkStatus re-verification call fails', function () {
    Http::fake([
        '*/auth/merchant/login' => Http::response(['data' => ['access_token' => 'tok']], 200),
        '*/transaction/4471920038/checkStatus' => Http::response([], 500),
    ]);

    $this->postJson('/payments/webhooks/nass', ['orderId' => '4471920038', 'responseCode' => '00'])
        ->assertStatus(401);
});

it('returns 401 when the callback payload lacks an orderId', function () {
    $this->postJson('/payments/webhooks/nass', ['responseCode' => '00'])
        ->assertStatus(401);
});
