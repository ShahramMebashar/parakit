<?php
declare(strict_types=1);

use Gutian\Parakit\Gateways\ZainCash\ZainCashJwt;
use Gutian\Parakit\Exceptions\InvalidWebhookSignatureException;

it('round-trips a signed JWT', function () {
    $jwt = new ZainCashJwt('shared-secret-shared-secret-1234');
    $token = $jwt->encode(['msisdn' => '07710000000', 'amount' => 5000]);
    $claims = $jwt->decode($token);
    expect($claims['amount'])->toBe(5000);
});

it('rejects a JWT with the wrong secret', function () {
    $jwt = new ZainCashJwt('shared-secret-shared-secret-1234');
    $token = $jwt->encode(['amount' => 5000]);

    (new ZainCashJwt('other-secret-other-secret-aaaaaa'))->decode($token);
})->throws(InvalidWebhookSignatureException::class);

it('rejects a tampered JWT', function () {
    $jwt = new ZainCashJwt('shared-secret-shared-secret-1234');
    $token = $jwt->encode(['amount' => 5000]);
    $tampered = substr($token, 0, -5) . 'XXXXX';
    $jwt->decode($tampered);
})->throws(InvalidWebhookSignatureException::class);

it('rejects an alg=none JWT (algorithm confusion attack)', function () {
    // Manually crafted JWT with alg=none — must never pass.
    $header = base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode(['amount' => 5000]));
    $token = strtr($header, '+/', '-_') . '.' . strtr($payload, '+/', '-_') . '.';

    (new ZainCashJwt('shared-secret-shared-secret-1234'))->decode($token);
})->throws(InvalidWebhookSignatureException::class);
