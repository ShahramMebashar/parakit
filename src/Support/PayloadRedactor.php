<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

final class PayloadRedactor
{
    private const REPLACEMENT = '[REDACTED]';
    private const PAN_CANDIDATE_REGEX = '/\b\d{13,19}\b/';

    /** @param string[] $keys */
    public function __construct(private readonly array $keys) {}

    public function redact(array $payload): array
    {
        $tokens = array_map('strtolower', $this->keys);
        return $this->walk($payload, $tokens);
    }

    /** @param string[] $tokens */
    private function walk(array $node, array $tokens): array
    {
        foreach ($node as $k => $v) {
            if (is_string($k) && $this->isSensitiveKey($k, $tokens)) {
                $node[$k] = self::REPLACEMENT;
                continue;
            }
            if (is_array($v)) {
                $node[$k] = $this->walk($v, $tokens);
            } elseif (is_string($v)) {
                $node[$k] = $this->redactPanCandidates($v);
            }
        }
        return $node;
    }

    /**
     * A key is sensitive if any configured token equals one of its word
     * components — splitting on `_`, `-`, `/`, whitespace, and camelCase
     * boundaries. So `'secret'` redacts `client_secret` and `apiSecret`
     * without sweeping up `'secretary'`; `'key'` redacts `api_key` and
     * `publicKey` without sweeping up `'keyboard'`.
     *
     * @param string[] $tokens
     */
    private function isSensitiveKey(string $key, array $tokens): bool
    {
        $lower = strtolower($key);
        if (in_array($lower, $tokens, true)) {
            return true;
        }
        $parts = preg_split('/[_\-\s\/]+|(?<=[a-z])(?=[A-Z])/', $key) ?: [];
        $parts = array_map('strtolower', $parts);
        foreach ($tokens as $tok) {
            if (in_array($tok, $parts, true)) {
                return true;
            }
        }
        return false;
    }

    private function redactPanCandidates(string $value): string
    {
        return preg_replace_callback(
            self::PAN_CANDIDATE_REGEX,
            fn (array $m) => self::luhnValid($m[0]) ? self::REPLACEMENT : $m[0],
            $value,
        );
    }

    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }
}
