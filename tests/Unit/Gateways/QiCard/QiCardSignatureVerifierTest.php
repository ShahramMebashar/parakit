<?php
declare(strict_types=1);

use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;
use Froshly\Parakit\Gateways\QiCard\QiCardSignatureVerifier;

beforeEach(function () {
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $privatePem);
    $this->privateKey = $privatePem;
    $this->publicKey = openssl_pkey_get_details($res)['key'];

    $this->payload = [
        'paymentId'    => 'b91e8d70-1ab7-4275-85a2-61f7dbb31410',
        'amount'       => 10000,
        'currency'     => 'IQD',
        'creationDate' => '2025-01-13T21:25:19',
        'status'       => 'SUCCESS',
    ];
});

function sign(array $payload, string $privatePem): string
{
    $canonical = QiCardSignatureVerifier::canonicalString($payload);
    $sig = '';
    openssl_sign($canonical, $sig, $privatePem, OPENSSL_ALGO_SHA256);
    return base64_encode($sig);
}

it('builds the canonical string exactly as documented (amount in 3dp, pipe-separated)', function () {
    expect(QiCardSignatureVerifier::canonicalString($this->payload))
        ->toBe('b91e8d70-1ab7-4275-85a2-61f7dbb31410|10000.000|IQD|2025-01-13T21:25:19|SUCCESS');
});

it('substitutes missing fields with a dash', function () {
    expect(QiCardSignatureVerifier::canonicalString([]))
        ->toBe('-|-|-|-|-');
});

it('accepts a valid signature', function () {
    $sig = sign($this->payload, $this->privateKey);

    QiCardSignatureVerifier::verify($sig, $this->payload, $this->publicKey);

    expect(true)->toBeTrue();
});

it('rejects a missing X-Signature header', function () {
    expect(fn () => QiCardSignatureVerifier::verify(null, $this->payload, $this->publicKey))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects an empty X-Signature header', function () {
    expect(fn () => QiCardSignatureVerifier::verify('', $this->payload, $this->publicKey))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects non-base64 garbage', function () {
    expect(fn () => QiCardSignatureVerifier::verify('not!!base64', $this->payload, $this->publicKey))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects a signature for a different payload', function () {
    $sig = sign($this->payload, $this->privateKey);
    $tampered = $this->payload;
    $tampered['amount'] = 20000;

    expect(fn () => QiCardSignatureVerifier::verify($sig, $tampered, $this->publicKey))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects a signature produced by a different key', function () {
    $otherRes = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($otherRes, $otherPrivate);
    $sig = sign($this->payload, $otherPrivate);

    expect(fn () => QiCardSignatureVerifier::verify($sig, $this->payload, $this->publicKey))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects when the public key PEM is malformed', function () {
    $sig = sign($this->payload, $this->privateKey);

    expect(fn () => QiCardSignatureVerifier::verify($sig, $this->payload, 'not-a-pem'))
        ->toThrow(InvalidWebhookSignatureException::class);
});
