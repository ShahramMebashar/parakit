<?php
declare(strict_types=1);

namespace Froshly\Parakit\Contracts;

use Froshly\Parakit\DTOs\RefundRequest;
use Froshly\Parakit\DTOs\RefundResponse;

interface SupportsRefund
{
    public function refund(RefundRequest $request): RefundResponse;
}
