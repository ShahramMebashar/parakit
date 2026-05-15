<?php
declare(strict_types=1);

namespace Froshly\Parakit\Contracts;

use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\Enums\Currency;

interface SupportsTokenization
{
    public function tokenize(PaymentRequest $request): string;
    public function chargeToken(string $token, int $amount, Currency $currency): PaymentResponse;
}
