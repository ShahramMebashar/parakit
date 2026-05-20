<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

use Froshly\Parakit\Models\PaymentTransaction;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fluent builder for a receipt — mirrors {@see \Froshly\Parakit\PaymentBuilder}.
 *
 *   Receipt::for($tx)
 *       ->template('classic')
 *       ->customer(['name' => 'Ada', 'email' => 'ada@example.com'])
 *       ->save();
 */
final class ReceiptBuilder
{
    private ReceiptType $type = ReceiptType::Payment;
    private ?string $template = null;
    private ?string $locale = null;
    private CustomerDetails $customer;

    public function __construct(
        private readonly ReceiptManager $manager,
        private readonly PaymentTransaction $transaction,
    ) {
        $this->customer = new CustomerDetails();
    }

    public function type(ReceiptType $type): self
    {
        $this->type = $type;
        return $this;
    }

    /** Shorthand for type(ReceiptType::Refund). */
    public function asRefund(): self
    {
        return $this->type(ReceiptType::Refund);
    }

    public function template(string $template): self
    {
        // Fail fast on a bad name here, not deep inside rendering.
        $this->template = $this->manager->resolveTemplate($template);
        return $this;
    }

    public function locale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Override the customer identity printed on the receipt.
     *
     * @param CustomerDetails|array<string,mixed> $customer
     */
    public function customer(CustomerDetails|array $customer): self
    {
        $this->customer = CustomerDetails::wrap($customer);
        return $this;
    }

    /** Terminal: build the receipt document. */
    public function generate(): ReceiptDocument
    {
        return $this->manager->generate(
            $this->transaction,
            $this->type,
            $this->customer,
            $this->template,
            $this->locale,
        );
    }

    public function stream(): StreamedResponse
    {
        return $this->generate()->stream();
    }

    public function download(): Response
    {
        return $this->generate()->download();
    }

    public function save(?string $disk = null, ?string $path = null): string
    {
        return $this->generate()->save($disk, $path);
    }
}
