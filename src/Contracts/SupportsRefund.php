<?php
declare(strict_types=1);

namespace Gutian\Parakit\Contracts;

use Gutian\Parakit\DTOs\RefundRequest;
use Gutian\Parakit\DTOs\RefundResponse;

interface SupportsRefund
{
    public function refund(RefundRequest $request): RefundResponse;
}
