<?php
declare(strict_types=1);

namespace Froshly\Parakit\Console;

use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Receipts\ReceiptManager;
use Froshly\Parakit\Receipts\ReceiptType;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Renders sample receipts so template designs can be eyeballed without
 * wiring up a real payment. Writes files to disk and prints their paths.
 */
class PreviewReceiptCommand extends Command
{
    protected $signature = 'parakit:receipts:preview
        {--template=modern : Template to render (modern|classic|minimal)}
        {--type=payment : Receipt type (payment|refund)}
        {--locale=en : Locale to render in (en|ar|ckb)}
        {--format=html : Output format (html|pdf) — html is fastest for design iteration}
        {--output= : Directory to write into (default: storage/parakit-receipts)}
        {--all : Render every template × type × locale combination}';

    protected $description = 'Render sample receipts to disk for previewing template designs';

    /** Locales the package ships translations for. */
    private const LOCALES = ['en', 'ar', 'ckb'];

    public function handle(ReceiptManager $manager): int
    {
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['html', 'pdf'], true)) {
            $this->error("Invalid --format \"{$format}\". Use html or pdf.");
            return self::FAILURE;
        }

        $dir = (string) ($this->option('output') ?: storage_path('parakit-receipts'));
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->error("Could not create output directory: {$dir}");
            return self::FAILURE;
        }

        try {
            $combos = $this->combinations();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        foreach ($combos as [$template, $type, $locale]) {
            $doc = $manager->generate($this->sampleTransaction($type), $type, [], $template, $locale);
            $body = $format === 'pdf' ? $doc->raw() : $doc->html();
            $file = "{$dir}/{$template}-{$type->value}-{$locale}.{$format}";

            file_put_contents($file, $body);
            $this->line("  <info>✓</info> {$file}");
        }

        $this->info(count($combos) . " receipt(s) written to {$dir}");

        return self::SUCCESS;
    }

    /**
     * Resolve the combinations to render.
     *
     * @return list<array{0:string,1:ReceiptType,2:string}>
     */
    private function combinations(): array
    {
        if ($this->option('all')) {
            $combos = [];
            foreach (ReceiptManager::TEMPLATES as $template) {
                foreach (ReceiptType::cases() as $type) {
                    foreach (self::LOCALES as $locale) {
                        $combos[] = [$template, $type, $locale];
                    }
                }
            }
            return $combos;
        }

        $template = (string) $this->option('template');
        if (!in_array($template, ReceiptManager::TEMPLATES, true)) {
            throw new InvalidArgumentException(
                "Unknown template \"{$template}\". Valid: " . implode(', ', ReceiptManager::TEMPLATES) . '.'
            );
        }

        $type = ReceiptType::tryFrom((string) $this->option('type'))
            ?? throw new InvalidArgumentException('Invalid --type. Use payment or refund.');

        return [[$template, $type, (string) $this->option('locale')]];
    }

    /** An unsaved transaction carrying representative sample data. */
    private function sampleTransaction(ReceiptType $type): PaymentTransaction
    {
        $isRefund = $type === ReceiptType::Refund;

        return new PaymentTransaction([
            'id'                     => (string) Str::ulid(),
            'gateway'                => 'fib',
            'reference'              => 'ORD-2048',
            'gateway_transaction_id' => 'pid_5f3a9c2e',
            'status'                 => $isRefund ? PaymentStatus::PartiallyRefunded : PaymentStatus::Paid,
            'amount'                 => 25000,
            'currency'               => Currency::IQD,
            'refunded_amount'        => $isRefund ? 10000 : 0,
            'correlation_id'         => (string) Str::ulid(),
            'metadata'               => [
                'customer_name'  => 'Ada Lovelace',
                'customer_email' => 'ada@example.com',
                'customer_phone' => '0770 000 0000',
            ],
            'paid_at'                => now(),
        ]);
    }
}
