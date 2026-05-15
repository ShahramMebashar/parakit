<?php
declare(strict_types=1);

namespace Shah\Parakit\Contracts;

use Shah\Parakit\DTOs\PaymentRequest;
use Shah\Parakit\DTOs\PaymentResponse;
use Shah\Parakit\Enums\Currency;

interface SupportsTokenization
{
    public function tokenize(PaymentRequest $request): string;
    public function chargeToken(string $token, int $amount, Currency $currency): PaymentResponse;
}
