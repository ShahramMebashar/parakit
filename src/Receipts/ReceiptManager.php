<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

use DateTimeImmutable;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Support\Money;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;

/**
 * Builds {@see ReceiptDocument} objects from payment transactions.
 *
 * Resolves the template, locale and customer details, then hands a lazy
 * render closure to the document so PDF work is deferred until delivery.
 */
final class ReceiptManager
{
    /** Templates shipped with the package. */
    public const TEMPLATES = ['modern', 'classic', 'minimal'];

    public function __construct(private readonly Container $app) {}

    /** Fluent entry point: Receipt::for($tx)->...->generate(). */
    public function for(PaymentTransaction $tx): ReceiptBuilder
    {
        return new ReceiptBuilder($this, $tx);
    }

    /**
     * Generate a receipt directly.
     *
     * @param CustomerDetails|array<string,mixed> $customer Overrides the
     *        customer identity printed on the receipt. Anything omitted falls
     *        back to the transaction's metadata, then to nothing.
     */
    public function generate(
        PaymentTransaction $tx,
        ReceiptType $type = ReceiptType::Payment,
        CustomerDetails|array $customer = [],
        ?string $template = null,
        ?string $locale = null,
    ): ReceiptDocument {
        /** @var array<string,mixed> $config */
        $config = config('parakit.receipts', []);

        $template = $this->resolveTemplate($template ?? ($config['template'] ?? 'modern'));
        $locale = $this->resolveLocale($tx, $config, $locale);
        $data = $this->buildData($tx, $type, $locale, $config, CustomerDetails::wrap($customer));

        $renderer = fn (): string => $this->app->make(PdfRenderer::class)
            ->render($this->renderHtml($data, $template, $locale));

        return new ReceiptDocument($data, $renderer, $config);
    }

    /** @throws InvalidArgumentException on an unknown template name. */
    public function resolveTemplate(string $template): string
    {
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown receipt template "%s". Valid templates: %s.',
                $template,
                implode(', ', self::TEMPLATES),
            ));
        }

        return $template;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function resolveLocale(PaymentTransaction $tx, array $config, ?string $override): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $key = $config['metadata']['locale_key'] ?? 'locale';
        $fromMeta = $tx->metadata[$key] ?? null;
        if (is_string($fromMeta) && $fromMeta !== '') {
            return $fromMeta;
        }

        $configured = $config['locale'] ?? 'app';

        return $configured === 'app'
            ? App::getLocale()
            : (string) $configured;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function buildData(
        PaymentTransaction $tx,
        ReceiptType $type,
        string $locale,
        array $config,
        CustomerDetails $customer,
    ): ReceiptData {
        $amount = (int) $tx->amount;

        // A payment receipt never shows refund figures, even on a row that was
        // later refunded — it documents the payment as it stood.
        $refunded = $type === ReceiptType::Refund ? (int) $tx->refunded_amount : 0;
        $net = $amount - $refunded;

        $isPartialRefund = $type === ReceiptType::Refund
            && $refunded > 0
            && $refunded < $amount;

        $meta = (array) ($tx->metadata ?? []);
        $keys = (array) ($config['metadata'] ?? []);

        return new ReceiptData(
            type: $type,
            transactionId: (string) $tx->id,
            gateway: (string) $tx->gateway,
            reference: (string) $tx->reference,
            gatewayTransactionId: $tx->gateway_transaction_id,
            status: $tx->status,
            currency: $tx->currency,
            amountMinor: $amount,
            amountFormatted: Money::format($amount, $tx->currency),
            refundedMinor: $refunded,
            refundedFormatted: Money::format($refunded, $tx->currency),
            netMinor: $net,
            netFormatted: Money::format($net, $tx->currency),
            isPartialRefund: $isPartialRefund,
            // Explicit customer details win; metadata is the fallback.
            customerName: $customer->name ?? $this->metaString($meta, $keys['name_key'] ?? 'customer_name'),
            customerEmail: $customer->email ?? $this->metaString($meta, $keys['email_key'] ?? 'customer_email'),
            customerPhone: $customer->phone ?? $this->metaString($meta, $keys['phone_key'] ?? 'customer_phone'),
            issuedAt: new DateTimeImmutable(),
            paidAt: $tx->paid_at?->toDateTimeImmutable(),
            locale: $locale,
            merchant: (array) ($config['merchant'] ?? []),
            metadata: $meta,
        );
    }

    /** Render the receipt's Blade view to HTML, scoped to the receipt locale. */
    private function renderHtml(ReceiptData $data, string $template, string $locale): string
    {
        $view = "parakit::receipts.{$template}.{$data->type->viewKey()}";
        $previous = App::getLocale();

        App::setLocale($locale);
        try {
            return view($view, ['receipt' => $data])->render();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function metaString(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;

        return (is_string($value) && $value !== '') ? $value : null;
    }
}
