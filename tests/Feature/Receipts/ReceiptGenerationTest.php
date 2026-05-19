<?php
declare(strict_types=1);

use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Facades\Receipt;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Receipts\CustomerDetails;
use Froshly\Parakit\Receipts\PdfRenderer;
use Froshly\Parakit\Receipts\ReceiptDocument;
use Froshly\Parakit\Tests\Doubles\RecordingPdfRenderer;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(fn () => $this->artisan('migrate'));

function makeTx(array $attrs = []): PaymentTransaction
{
    return PaymentTransaction::create(array_merge([
        'gateway'                => 'fib',
        'reference'              => 'ord_1024',
        'gateway_transaction_id' => 'pid_xyz',
        'status'                 => PaymentStatus::Paid,
        'amount'                 => 25000,
        'currency'               => Currency::IQD,
        'correlation_id'         => '01HCORR',
        'paid_at'                => now(),
    ], $attrs));
}

/** Swap dompdf for a renderer that records the HTML it is handed. */
function recorder(): RecordingPdfRenderer
{
    app()->instance(PdfRenderer::class, $r = new RecordingPdfRenderer());

    return $r;
}

it('generates a real PDF document', function () {
    $doc = Receipt::for(makeTx())->generate();

    expect($doc)->toBeInstanceOf(ReceiptDocument::class)
        ->and($doc->raw())->toStartWith('%PDF-');
});

it('exposes rendered HTML without invoking dompdf', function () {
    $html = Receipt::for(makeTx())->generate()->html();

    expect($html)->toContain('<!DOCTYPE html>')->toContain('ord_1024');
});

it('renders a payment receipt through every shipped template', function (string $template) {
    $rec = recorder();

    Receipt::for(makeTx())->template($template)->generate()->raw();

    expect($rec->html)
        ->toContain('ord_1024')
        ->toContain('25000')
        ->toContain('Paid');
})->with(['modern', 'classic', 'minimal']);

it('rejects an unknown template name', function () {
    expect(fn () => Receipt::for(makeTx())->template('fancy'))
        ->toThrow(InvalidArgumentException::class, 'fancy');
});

it('renders a refund receipt with the refunded amount and partial-refund note', function () {
    $rec = recorder();
    $tx = makeTx(['status' => PaymentStatus::PartiallyRefunded, 'refunded_amount' => 10000]);

    Receipt::for($tx)->asRefund()->generate()->raw();

    expect($rec->html)
        ->toContain('10000')
        ->toContain(__('parakit::receipts.partial_refund_note'));
});

it('does not flag a full refund as partial', function () {
    $rec = recorder();
    $tx = makeTx(['status' => PaymentStatus::Refunded, 'refunded_amount' => 25000]);

    Receipt::for($tx)->asRefund()->generate()->raw();

    expect($rec->html)->not->toContain(__('parakit::receipts.partial_refund_note'));
});

it('keeps refund figures off a payment receipt for a later-refunded row', function () {
    $rec = recorder();
    $tx = makeTx(['refunded_amount' => 9000]);

    $doc = Receipt::for($tx)->generate();
    $doc->raw();

    expect($doc->data->refundedMinor)->toBe(0);
});

it('prints explicit customer details over transaction metadata', function () {
    $rec = recorder();
    $tx = makeTx(['metadata' => ['customer_name' => 'From Metadata']]);

    Receipt::for($tx)
        ->customer(new CustomerDetails(name: 'Ada Lovelace', email: 'ada@example.com'))
        ->generate()->raw();

    expect($rec->html)->toContain('Ada Lovelace')->not->toContain('From Metadata');
});

it('falls back to transaction metadata for customer details', function () {
    $rec = recorder();
    $tx = makeTx(['metadata' => ['customer_name' => 'Grace Hopper']]);

    Receipt::for($tx)->generate()->raw();

    expect($rec->html)->toContain('Grace Hopper');
});

it('accepts a plain array of customer details', function () {
    $rec = recorder();

    Receipt::for(makeTx())->customer(['name' => 'Array Person'])->generate()->raw();

    expect($rec->html)->toContain('Array Person');
});

it('renders RTL markup for Kurdish and restores the app locale', function () {
    $rec = recorder();
    $before = App::getLocale();

    Receipt::for(makeTx())->locale('ckb')->generate()->raw();

    expect($rec->html)->toContain('dir="rtl"')
        ->and(App::getLocale())->toBe($before);
});

it('formats USD amounts in major units', function () {
    $rec = recorder();

    Receipt::for(makeTx(['currency' => Currency::USD, 'amount' => 1234]))->generate()->raw();

    expect($rec->html)->toContain('12.34');
});

it('saves the PDF to a disk and returns the stored path', function () {
    Storage::fake('local');

    $path = Receipt::for(makeTx())->save();

    expect($path)->toBe('receipts/payment-ord_1024.pdf');
    Storage::disk('local')->assertExists($path);
});

it('builds an inline stream response and an attachment download response', function () {
    recorder();
    $doc = Receipt::for(makeTx())->generate();

    expect($doc->stream())->toBeInstanceOf(StreamedResponse::class)
        ->and($doc->download()->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('payment-ord_1024.pdf');
});
