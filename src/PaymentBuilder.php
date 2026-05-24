<?php
declare(strict_types=1);

namespace Froshly\Parakit;

use InvalidArgumentException;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\Enums\Currency;

final class PaymentBuilder
{
    private ?string $driver = null;
    private ?int $amount = null;
    private Currency $currency = Currency::IQD;
    private string $description = '';
    private ?string $idempotencyKey = null;
    private array $metadata = [];
    private ?string $callbackUrl = null;
    private ?string $returnUrl = null;
    private ?string $customerPhone = null;

    public function __construct(
        private readonly PaymentManager $manager,
        private readonly string $reference,
    ) {}

    public function driver(string $name): self
    {
        $this->driver = $name;
        return $this;
    }

    public function amount(int $minor, Currency $currency): self
    {
        $this->amount = $minor;
        $this->currency = $currency;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function idempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;
        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function callbackUrl(string $url): self
    {
        $this->callbackUrl = $url;
        return $this;
    }

    public function returnUrl(string $url): self
    {
        $this->returnUrl = $url;
        return $this;
    }

    public function customerPhone(string $phone): self
    {
        $this->customerPhone = $phone;
        return $this;
    }

    public function charge(): PaymentResponse
    {
        if ($this->amount === null) {
            throw new InvalidArgumentException('Amount is required before charge().');
        }
        if ($this->description === '') {
            // FIB rejects empty description; ZainCash uses it as serviceType.
            // Fail locally with a clear cause rather than waiting for a 400.
            throw new InvalidArgumentException('Description is required before charge().');
        }

        return $this->manager->driver($this->driver)->charge(new PaymentRequest(
            reference: $this->reference,
            amount: $this->amount,
            currency: $this->currency,
            description: $this->description,
            customerPhone: $this->customerPhone,
            callbackUrl: $this->callbackUrl,
            returnUrl: $this->returnUrl,
            idempotencyKey: $this->idempotencyKey,
            metadata: $this->metadata,
        ));
    }
}
