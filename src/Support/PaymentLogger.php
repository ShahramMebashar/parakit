<?php
declare(strict_types=1);

namespace Froshly\Parakit\Support;

use Froshly\Parakit\Models\PaymentLog;

final class PaymentLogger
{
    private ?PayloadRedactor $redactor = null;

    public function record(
        string $action,
        string $gateway,
        ?string $endpoint,
        ?int $statusCode,
        ?int $durationMs,
        array $request,
        array $response,
        string $correlationId,
        ?string $errorMessage = null,
    ): void {
        if (!config('parakit.logging.enabled', true)) {
            return;
        }

        $redactor = $this->redactor();

        PaymentLog::create([
            'correlation_id' => $correlationId,
            'gateway' => $gateway,
            'action' => $action,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'request' => $redactor->redact($request),
            'response' => $redactor->redact($response),
            'error_message' => $errorMessage,
        ]);
    }

    private function redactor(): PayloadRedactor
    {
        return $this->redactor ??= new PayloadRedactor(
            (array) config('parakit.logging.redact_keys', []),
        );
    }
}
