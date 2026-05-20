<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

/**
 * Customer identity printed on a receipt.
 *
 * Gateways rarely return a usable customer name or e-mail, so the host
 * application supplies these when it has them — either as this DTO or a plain
 * array passed to the receipt API. All fields are optional; a receipt renders
 * fine without any of them.
 */
final readonly class CustomerDetails
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}

    /**
     * Build from a loose array — keys: name, email, phone (all optional).
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: self::str($data['name'] ?? null),
            email: self::str($data['email'] ?? null),
            phone: self::str($data['phone'] ?? null),
        );
    }

    /** Normalise any input (DTO or array) to a CustomerDetails instance. */
    public static function wrap(self|array $customer): self
    {
        return $customer instanceof self ? $customer : self::fromArray($customer);
    }

    private static function str(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }
}
