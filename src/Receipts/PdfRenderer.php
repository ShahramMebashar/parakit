<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

/**
 * The only dompdf-aware class in the package.
 *
 * dompdf is an optional dependency since v0.9.1; merchants who don't generate
 * PDF receipts shouldn't pay for ~5MB of HTML-to-PDF code. If a host app
 * resolves PdfRenderer without `barryvdh/laravel-dompdf` installed, we throw
 * a helpful install hint rather than the cryptic "binding not found" the
 * container would otherwise produce.
 */
class PdfRenderer
{
    /**
     * @param array{paper?:string,orientation?:string,options?:array<string,mixed>} $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /** Render an HTML document to raw PDF bytes. */
    public function render(string $html): string
    {
        if (!app()->bound('dompdf.wrapper')) {
            throw new \RuntimeException(
                'Parakit receipts require barryvdh/laravel-dompdf. '
                . 'Install it with: composer require barryvdh/laravel-dompdf'
            );
        }

        $pdf = app('dompdf.wrapper');

        if (!empty($this->config['options'])) {
            $pdf->setOptions($this->config['options']);
        }

        $pdf->setPaper(
            $this->config['paper'] ?? 'a4',
            $this->config['orientation'] ?? 'portrait',
        );

        return $pdf->loadHTML($html)->output();
    }
}
