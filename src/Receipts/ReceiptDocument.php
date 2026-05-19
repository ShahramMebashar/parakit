<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

use Closure;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A generated receipt, ready to be delivered.
 *
 * PDF bytes are rendered lazily on first access and memoised — constructing a
 * document is cheap; dompdf only runs when a delivery method is called.
 *
 * Delivery is deliberately limited to bytes / HTTP responses / disk. E-mailing
 * a receipt is the host application's responsibility, not this package's.
 */
final class ReceiptDocument
{
    private ?string $pdf = null;

    /**
     * @param Closure():string    $renderer Produces the raw PDF bytes.
     * @param array<string,mixed> $config   The parakit.receipts config block.
     */
    public function __construct(
        public readonly ReceiptData $data,
        private readonly Closure $renderer,
        private readonly array $config = [],
    ) {}

    /** Raw PDF bytes (rendered once, then memoised). */
    public function raw(): string
    {
        return $this->pdf ??= ($this->renderer)();
    }

    public function filename(): string
    {
        $template = (string) ($this->config['filename'] ?? '{type}-{reference}.pdf');

        return strtr($template, [
            '{type}'      => $this->data->type->value,
            '{reference}' => $this->fsSafe($this->data->reference),
            '{id}'        => $this->fsSafe($this->data->transactionId),
        ]);
    }

    /** Collapse anything filesystem-unfriendly to a dash; keep word chars. */
    private function fsSafe(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? '';

        return trim($clean, '-') ?: 'receipt';
    }

    /** Inline PDF response (renders in the browser). */
    public function stream(): StreamedResponse
    {
        return response()->streamDownload(
            fn () => print($this->raw()),
            $this->filename(),
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
    }

    /** Attachment PDF response (browser download dialog). */
    public function download(): Response
    {
        return response($this->raw(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->filename() . '"',
        ]);
    }

    /** Persist the PDF to a filesystem disk; returns the stored path. */
    public function save(?string $disk = null, ?string $path = null): string
    {
        $disk ??= $this->config['disk'] ?? 'local';
        $path ??= trim((string) ($this->config['path'] ?? 'receipts'), '/') . '/' . $this->filename();

        Storage::disk($disk)->put($path, $this->raw());

        return $path;
    }
}
