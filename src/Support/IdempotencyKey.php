<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

use Froshly\Parakit\DTOs\PaymentRequest;

final class IdempotencyKey
{
    public static function derive(string $gateway, string $reference, int $amount, string $currency): string
    {
        return hash('sha256', implode('|', [$gateway, $reference, (string) $amount, $currency]));
    }

    /** Fold a free-text user key into a 64-char hex digest, namespaced by gateway. */
    public static function forGateway(string $gatewayName, string $localKey): string
    {
        return hash('sha256', $gatewayName . '|' . $localKey);
    }

    public static function forGatewayOperation(string $gatewayName, string $operation, string $localKey): string
    {
        return hash('sha256', implode('|', [$gatewayName, $operation, $localKey]));
    }

    public static function localForRequest(string $gatewayName, PaymentRequest $request): string
    {
        return $request->idempotencyKey ?? self::derive(
            $gatewayName,
            $request->reference,
            $request->amount,
            $request->currency->value,
        );
    }

    public static function gatewayHexPrefix(string $gatewayName, string $localKey, int $length): string
    {
        return substr(self::forGateway($gatewayName, $localKey), 0, $length);
    }

    public static function gatewayHexPrefixForOperation(
        string $gatewayName,
        string $operation,
        string $localKey,
        int $length,
    ): string {
        return substr(self::forGatewayOperation($gatewayName, $operation, $localKey), 0, $length);
    }

    public static function gatewayHexPrefixForRequest(
        string $gatewayName,
        PaymentRequest $request,
        int $length,
    ): string {
        return self::gatewayHexPrefix($gatewayName, self::localForRequest($gatewayName, $request), $length);
    }

    public static function gatewayNumericForRequest(
        string $gatewayName,
        PaymentRequest $request,
        int $hexCharacters = 15,
    ): string {
        return (string) hexdec(self::gatewayHexPrefixForRequest($gatewayName, $request, $hexCharacters));
    }

    public static function cacheKey(string $gatewayName, string $operation, string $localKey): string
    {
        return "parakit:{$operation}:idem:" . self::forGatewayOperation($gatewayName, $operation, $localKey);
    }
}
