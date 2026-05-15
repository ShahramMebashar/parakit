<?php
declare(strict_types=1);

namespace Gutian\Parakit\Contracts;

use Gutian\Parakit\DTOs\PaymentResponse;

interface SupportsStatusCheck
{
    public function status(string $gatewayTransactionId): PaymentResponse;
}
