<?php
declare(strict_types=1);

use Froshly\Parakit\Receipts\PdfRenderer;

it('renders HTML to real PDF bytes', function () {
    $pdf = (new PdfRenderer(['paper' => 'a4', 'orientation' => 'portrait']))
        ->render('<html lang="en"><body><h1>Receipt</h1></body></html>');

    expect($pdf)->toStartWith('%PDF-')->and(strlen($pdf))->toBeGreaterThan(400);
});

it('applies dompdf options without error', function () {
    $pdf = (new PdfRenderer([
        'paper'       => 'a4',
        'orientation' => 'portrait',
        'options'     => ['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false],
    ]))->render('<html lang="ar" dir="rtl"><body><p>إيصال</p></body></html>');

    expect($pdf)->toStartWith('%PDF-');
});

it('throws a helpful install hint when laravel-dompdf is not installed', function () {
    // Simulate an app where dompdf is not wired up by hiding the container binding.
    app()->forgetInstance('dompdf.wrapper');
    app()->bind('dompdf.wrapper', null);
    app()->offsetUnset('dompdf.wrapper');

    expect(fn () => (new PdfRenderer())->render('<p>x</p>'))
        ->toThrow(\RuntimeException::class, 'composer require barryvdh/laravel-dompdf');
});
