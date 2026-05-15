<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\ZainCash;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;

/**
 * HS256-pinned JWT verifier for ZainCash v2 redirect and webhook callbacks.
 *
 * ZainCash v2 signs callbacks with the merchant API key using HS256. Algorithm
 * is pinned at decode time — `alg: none` and asymmetric algorithms are rejected
 * by firebase/php-jwt when only the symmetric key is supplied, defending
 * against algorithm-confusion attacks. There is no encode(): v2 never requires
 * the merchant to sign outbound requests (those use an OAuth2 Bearer token).
 */
final class ZainCashJwt
{
    private const ALG = 'HS256';

    public function __construct(private readonly string $secret) {}

    /** @return array<string, mixed> */
    public function decode(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, self::ALG));
        } catch (\Throwable $e) {
            throw new InvalidWebhookSignatureException(
                'ZainCash JWT invalid: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return (array) json_decode((string) json_encode($decoded), true);
    }
}
