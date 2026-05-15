<?php
declare(strict_types=1);

use Froshly\Parakit\Gateways\ZainCash\ZainCashJwt;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('parakit.gateways.zaincash', [
        'driver' => 'zaincash',
        'base_url' => 'https://test.zaincash.iq',
        'merchant_id' => 'mer_1',
        'msisdn' => '07710000000',
        'secret' => 'shared-secret-shared-secret-1234',
    ]);
});

it('verifies a valid ZainCash callback JWT and applies state transition', function () {
    PaymentTransaction::create([
        'gateway' => 'zaincash', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'zc_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    $jwt = new ZainCashJwt('shared-secret-shared-secret-1234');
    $token = $jwt->encode([
        'id' => 'zc_1', 'status' => 'success',
        'orderid' => 'ord_1', 'amount' => 5000, 'iat' => time(),
    ]);

    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(200);
    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('rejects a forged JWT with 401', function () {
    $jwt = new ZainCashJwt('wrong-secret-wrong-secret-aaaaa1');
    $token = $jwt->encode(['id' => 'zc_1', 'status' => 'success', 'iat' => time()]);

    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(401);
});
