<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

const ZC_WEBHOOK_SECRET = 'shared-secret-shared-secret-1234';

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('parakit.gateways.zaincash', [
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => ZC_WEBHOOK_SECRET,
    ]);
});

function zcCallbackToken(array $overrides = [], string $secret = ZC_WEBHOOK_SECRET): string
{
    $claims = array_replace_recursive([
        'eventType' => 'STATUS_CHANGED',
        'eventId' => 'evt_1',
        'timestamp' => gmdate('c'),
        'data' => [
            'transactionId' => 'zc_1',
            'orderId' => 'ord_1',
            'customerMsisdn' => '9647801234567',
            'currentStatus' => 'SUCCESS',
            'amount' => ['currency' => 'IQD', 'value' => 5000],
        ],
    ], $overrides);

    return JWT::encode($claims, $secret, 'HS256');
}

function seedPendingZcTransaction(): void
{
    PaymentTransaction::create([
        'gateway' => 'zaincash', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'zc_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);
}

it('verifies a redirect callback token (token field) and applies the transition', function () {
    seedPendingZcTransaction();

    $this->postJson('/payments/webhooks/zaincash', ['token' => zcCallbackToken()])
        ->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('verifies a webhook callback token (webhook_token field)', function () {
    seedPendingZcTransaction();

    $this->postJson('/payments/webhooks/zaincash', ['webhook_token' => zcCallbackToken()])
        ->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('maps a STATUS_CHANGED FAILED event to Failed', function () {
    seedPendingZcTransaction();

    $token = zcCallbackToken(['data' => ['currentStatus' => 'FAILED', 'errorMessage' => 'Error!']]);
    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Failed);
});

it('maps a REFUND_COMPLETED event to Refunded', function () {
    PaymentTransaction::create([
        'gateway' => 'zaincash', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'zc_1',
        'status' => PaymentStatus::Paid, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    $token = zcCallbackToken([
        'eventType' => 'REFUND_COMPLETED',
        'data' => ['currentStatus' => 'REFUNDED'],
    ]);
    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Refunded);
});

it('rejects a forged callback token with 401', function () {
    $token = zcCallbackToken(secret: 'wrong-secret-wrong-secret-aaaaa1');

    $this->postJson('/payments/webhooks/zaincash', ['token' => $token])->assertStatus(401);
});

it('rejects a request with no token with 401', function () {
    $this->postJson('/payments/webhooks/zaincash', [])->assertStatus(401);
});
