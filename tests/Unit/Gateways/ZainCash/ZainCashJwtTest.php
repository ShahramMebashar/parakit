<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Froshly\Parakit\Gateways\ZainCash\ZainCashJwt;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;

const ZC_SECRET = 'shared-secret-shared-secret-1234';

it('decodes a JWT signed with the matching secret', function () {
    $token = JWT::encode(['eventId' => 'e1', 'data' => ['orderId' => 'ord_1']], ZC_SECRET, 'HS256');
    $claims = (new ZainCashJwt(ZC_SECRET))->decode($token);

    expect($claims['eventId'])->toBe('e1')
        ->and($claims['data']['orderId'])->toBe('ord_1');
});

it('rejects a JWT signed with the wrong secret', function () {
    $token = JWT::encode(['eventId' => 'e1'], 'other-secret-other-secret-aaaaaa', 'HS256');
    (new ZainCashJwt(ZC_SECRET))->decode($token);
})->throws(InvalidWebhookSignatureException::class);

it('rejects a tampered JWT', function () {
    $token = JWT::encode(['eventId' => 'e1'], ZC_SECRET, 'HS256');
    (new ZainCashJwt(ZC_SECRET))->decode(substr($token, 0, -5) . 'XXXXX');
})->throws(InvalidWebhookSignatureException::class);

it('rejects an alg=none JWT (algorithm confusion attack)', function () {
    $header = strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_');
    $payload = strtr(base64_encode(json_encode(['eventId' => 'e1'])), '+/', '-_');
    (new ZainCashJwt(ZC_SECRET))->decode($header . '.' . $payload . '.');
})->throws(InvalidWebhookSignatureException::class);
