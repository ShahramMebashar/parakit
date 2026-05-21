<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Gateways\QiCard\QiCardSignatureVerifier;
use Froshly\Parakit\Models\PaymentTransaction;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');

    [$privatePem, $publicPem] = generateRsaKeypair();
    $this->qicardPrivateKey = $privatePem;
    $this->qicardPublicKey = $publicPem;

    config()->set('parakit.gateways.qicard', [
        'driver'      => 'qicard',
        'base_url'    => 'https://uat-sandbox-3ds-api.qi.iq',
        'username'    => 'u',
        'password'    => 'p',
        'terminal_id' => '237984',
        'public_key'  => $publicPem,
    ]);

    PaymentTransaction::create([
        'gateway' => 'qicard',
        'reference' => 'INV-QI-WH',
        'gateway_transaction_id' => 'f2bb43a8-488a-4281-977b-5b3418fc3c67',
        'status' => PaymentStatus::Pending,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => 'c',
    ]);
});

function generateRsaKeypair(): array
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    $publicPem = openssl_pkey_get_details($res)['key'];
    return [$privatePem, $publicPem];
}

function signQiCardPayload(array $payload, string $privatePem): string
{
    $canonical = QiCardSignatureVerifier::canonicalString($payload);
    $signature = '';
    openssl_sign($canonical, $signature, $privatePem, OPENSSL_ALGO_SHA256);
    return base64_encode($signature);
}

it('accepts a valid X-Signature and transitions the transaction to Paid', function () {
    $payload = [
        'paymentId'    => 'f2bb43a8-488a-4281-977b-5b3418fc3c67',
        'status'       => 'SUCCESS',
        'amount'       => 5000,
        'currency'     => 'IQD',
        'creationDate' => '2026-05-21T10:00:00',
    ];

    $this->postJson(
        '/payments/webhooks/qicard',
        $payload,
        ['X-Signature' => signQiCardPayload($payload, $this->qicardPrivateKey)],
    )->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('rejects a tampered payload as 401', function () {
    $payload = [
        'paymentId'    => 'f2bb43a8-488a-4281-977b-5b3418fc3c67',
        'status'       => 'SUCCESS',
        'amount'       => 5000,
        'currency'     => 'IQD',
        'creationDate' => '2026-05-21T10:00:00',
    ];
    $sig = signQiCardPayload($payload, $this->qicardPrivateKey);

    // Flip status after signing — verifier must reject.
    $payload['status'] = 'FAILED';

    $this->postJson('/payments/webhooks/qicard', $payload, ['X-Signature' => $sig])
        ->assertStatus(401);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Pending);
});

it('rejects a webhook with no X-Signature when a public key is configured', function () {
    $this->postJson('/payments/webhooks/qicard', [
        'paymentId'    => 'f2bb43a8-488a-4281-977b-5b3418fc3c67',
        'status'       => 'SUCCESS',
        'amount'       => 5000,
        'currency'     => 'IQD',
        'creationDate' => '2026-05-21T10:00:00',
    ])->assertStatus(401);
});

it('falls back to a server-to-server status re-check when no public key is configured', function () {
    config()->set('parakit.gateways.qicard.public_key', null);

    Http::fake([
        '*/api/v1/payment/*/status' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/status_success.json'), true),
            200,
        ),
    ]);

    $this->postJson('/payments/webhooks/qicard', [
        'paymentId' => 'f2bb43a8-488a-4281-977b-5b3418fc3c67',
        'status'    => 'CREATED',
    ])->assertStatus(200);

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
});

it('rejects an empty body as a verification failure', function () {
    $this->postJson('/payments/webhooks/qicard', [])->assertStatus(401);
});
