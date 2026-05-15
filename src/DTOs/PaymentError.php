<?php
declare(strict_types=1);

namespace Froshly\Parakit\DTOs;

use Froshly\Parakit\Enums\PaymentErrorCode;

final readonly class PaymentError
{
    public function __construct(
        public PaymentErrorCode $code,
        public string $rawCode,
        public string $rawMessage,
    ) {}

    public function message(?string $locale = null): string
    {
        $key = 'parakit::payments.errors.' . $this->code->value;
        $translated = trans($key, [], $locale);
        return $translated === $key ? $this->rawMessage : $translated;
    }
}
