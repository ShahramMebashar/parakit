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
        $lowerKeys = array_map('strtolower', $this->keys);
        return $this->walk($payload, $lowerKeys);
    }

    private function walk(array $node, array $lowerKeys): array
    {
        foreach ($node as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $lowerKeys, true)) {
                $node[$k] = self::REPLACEMENT;
                continue;
            }
            if (is_array($v)) {
                $node[$k] = $this->walk($v, $lowerKeys);
            } elseif (is_string($v)) {
                $node[$k] = $this->redactPanCandidates($v);
            }
        }
        return $node;
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
