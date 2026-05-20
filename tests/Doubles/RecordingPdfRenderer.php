<?php
declare(strict_types=1);

namespace Froshly\Parakit\Tests\Doubles;

use Froshly\Parakit\Receipts\PdfRenderer;

/**
 * Test double that records the HTML it was asked to render and returns a
 * recognisable stub instead of running dompdf — keeps the suite fast and lets
 * tests assert on the rendered template output directly.
 */
final class RecordingPdfRenderer extends PdfRenderer
{
    public ?string $html = null;

    public function __construct()
    {
        parent::__construct([]);
    }

    public function render(string $html): string
    {
        $this->html = $html;

        return "%PDF-FAKE\n" . $html;
    }
}
