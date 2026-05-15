<?php
declare(strict_types=1);

namespace Shah\Parakit\Contracts;

use Shah\Parakit\DTOs\PaymentResponse;

interface SupportsStatusCheck
{
    public function status(string $gatewayTransactionId): PaymentResponse;
}
