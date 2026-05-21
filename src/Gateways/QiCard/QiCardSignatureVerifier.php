<?php
declare(strict_types=1);

namespace Froshly\Parakit\Gateways\QiCard;

use Froshly\Parakit\Exceptions\InvalidWebhookSignatureException;

/**
 * Verifies the `X-Signature` header on QiCard webhook notifications.
 *
 * QiCard signs the canonical concatenation
 *
 *     paymentId | amount(3dp) | currency | creationDate | status
 *
 * with the gateway's RSA-2048 private key (SHA-256), base64-encoded into the
 * header. We verify with the merchant-provisioned public key. Algorithm is
 * pinned to OPENSSL_ALGO_SHA256 — never derived from the key — so a malicious
 * signer cannot downgrade us.
 *
 * Any verification failure throws InvalidWebhookSignatureException, which the
 * webhook controller turns into HTTP 401 plus a WebhookVerificationFailed
 * event (with credentials redacted).
 */
final class QiCardSignatureVerifier
{
    /**
     * @param array<string, mixed> $payload The decoded webhook JSON body.
     */
    public static function verify(?string $signatureHeader, array $payload, string $publicKeyPem): void
    {
        if (!is_string($signatureHeader) || $signatureHeader === '') {
            throw new InvalidWebhookSignatureException('QiCard webhook missing X-Signature header');
        }

        $decoded = base64_decode($signatureHeader, true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidWebhookSignatureException('QiCard X-Signature is not valid base64');
        }

        // Parse the PEM on every call — caching it would let a rotated key
        // keep validating against the old key until the Octane worker recycles.
        // openssl_pkey_get_public is microseconds; not worth the staleness risk.
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new InvalidWebhookSignatureException('QiCard public key is not a valid PEM');
        }

        $result = openssl_verify(self::canonicalString($payload), $decoded, $key, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new InvalidWebhookSignatureException('QiCard X-Signature did not verify');
        }
    }

    /**
     * Build the canonical signing string. Missing or null values become `-`,
     * matching QiCard's documented format. `amount` is always 3 decimal places
     * (QiCard's IQD signature format) — distinct from the 2dp used in REST
     * bodies.
     *
     * @param array<string, mixed> $payload
     */
    public static function canonicalString(array $payload): string
    {
        $amount = $payload['amount'] ?? null;
        $amountStr = is_numeric($amount)
            ? number_format((float) $amount, 3, '.', '')
            : '-';

        $fields = [
            self::stringOrDash($payload['paymentId'] ?? null),
            $amountStr,
            self::stringOrDash($payload['currency'] ?? null),
            self::stringOrDash($payload['creationDate'] ?? null),
            self::stringOrDash($payload['status'] ?? null),
        ];

        return implode('|', $fields);
    }

    private static function stringOrDash(mixed $v): string
    {
        if ($v === null) {
            return '-';
        }
        $s = is_scalar($v) ? (string) $v : '';
        return $s === '' ? '-' : $s;
    }
}
