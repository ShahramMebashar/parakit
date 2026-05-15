<?php
declare(strict_types=1);

namespace Shah\Parakit\Contracts;

use Shah\Parakit\DTOs\RefundRequest;
use Shah\Parakit\DTOs\RefundResponse;

interface SupportsRefund
{
    public function refund(RefundRequest $request): RefundResponse;
}
