<?php
declare(strict_types=1);

namespace Froshly\Parakit\Contracts;

use Froshly\Parakit\DTOs\PaymentResponse;

interface SupportsStatusCheck
{
    public function status(string $gatewayTransactionId): PaymentResponse;
}
