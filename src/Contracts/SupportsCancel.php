<?php
declare(strict_types=1);

namespace Froshly\Parakit\Contracts;

use Froshly\Parakit\DTOs\PaymentResponse;

/**
 * Optional capability: cancel an active payment that has not been paid yet.
 */
interface SupportsCancel
{
    public function cancel(string $gatewayTransactionId): PaymentResponse;
}
