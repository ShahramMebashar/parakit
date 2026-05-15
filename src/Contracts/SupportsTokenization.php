<?php
declare(strict_types=1);

namespace Gutian\Parakit\Contracts;

use Gutian\Parakit\DTOs\PaymentRequest;
use Gutian\Parakit\DTOs\PaymentResponse;
use Gutian\Parakit\Enums\Currency;

interface SupportsTokenization
{
    public function tokenize(PaymentRequest $request): string;
    public function chargeToken(string $token, int $amount, Currency $currency): PaymentResponse;
}
