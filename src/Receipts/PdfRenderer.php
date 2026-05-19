<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

use Froshly\Parakit\Exceptions\PaymentException;

/**
 * The only dompdf-aware class in the package.
 *
 * Everything else depends on this thin seam, so dompdf can be swapped or
 * mocked without touching receipt generation logic.
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
            throw new PaymentException(
                'PDF receipts require barryvdh/laravel-dompdf. Run "composer require barryvdh/laravel-dompdf".'
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
