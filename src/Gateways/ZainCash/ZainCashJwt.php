<?php
declare(strict_types=1);

namespace Shah\Parakit\Gateways\ZainCash;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Shah\Parakit\Exceptions\InvalidWebhookSignatureException;

/**
 * HS256-pinned JWT helper for ZainCash init/webhook payloads.
 *
 * Algorithm is pinned to HS256 at decode time — `alg: none` and asymmetric
 * algorithms (RS256, ES256) are rejected by firebase/php-jwt when only the
 * symmetric key is supplied, which defends against algorithm-confusion
 * attacks where an attacker switches the header to bypass signature checks.
 */
final class ZainCashJwt
{
    private const ALG = 'HS256';

    public function __construct(private readonly string $secret) {}

    public function encode(array $claims): string
    {
        try {
            return JWT::encode($claims, $this->secret, self::ALG);
        } catch (\Throwable $e) {
            // firebase/php-jwt v7 rejects HS256 keys < 32 bytes at encode time
            // ("Provided key is too short"). Surface this as a parakit error
            // rather than leaking the underlying DomainException.
            throw new InvalidWebhookSignatureException(
                'ZainCash JWT encode failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

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
