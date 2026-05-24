<?php
declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->previewDir = sys_get_temp_dir() . '/parakit-preview-' . Str::random(8);
});

afterEach(function () {
    File::deleteDirectory($this->previewDir);
});

it('renders a single HTML preview, RTL-aware', function () {
    $this->artisan('parakit:receipts:preview', [
        '--template' => 'classic',
        '--type'     => 'payment',
        '--locale'   => 'ckb',
        '--output'   => $this->previewDir,
    ])->assertSuccessful();

    $file = "{$this->previewDir}/classic-payment-ckb.html";
    expect(file_exists($file))->toBeTrue()
        ->and(file_get_contents($file))->toContain('dir="rtl"');
});

it('renders the full template × type × locale matrix with --all', function () {
    $this->artisan('parakit:receipts:preview', [
        '--all'    => true,
        '--output' => $this->previewDir,
    ])->assertSuccessful();

    // 3 templates × 2 types × 3 locales.
    expect(glob("{$this->previewDir}/*.html"))->toHaveCount(18);
});

it('renders a real PDF when --format=pdf', function () {
    $this->artisan('parakit:receipts:preview', [
        '--template' => 'modern',
        '--format'   => 'pdf',
        '--output'   => $this->previewDir,
    ])->assertSuccessful();

    $file = "{$this->previewDir}/modern-payment-en.pdf";
    expect(file_get_contents($file))->toStartWith('%PDF-');
});

it('fails on an unknown template', function () {
    $this->artisan('parakit:receipts:preview', [
        '--template' => 'fancy',
        '--output'   => $this->previewDir,
    ])->assertFailed();
});

it('fails on an unknown format', function () {
    $this->artisan('parakit:receipts:preview', [
        '--format'   => 'docx',
        '--output'   => $this->previewDir,
    ])->assertFailed();
});
