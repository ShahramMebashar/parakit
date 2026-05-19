<?php
declare(strict_types=1);

use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Receipts\ReceiptData;
use Froshly\Parakit\Receipts\ReceiptType;

function receiptData(array $overrides = []): ReceiptData
{
    $defaults = [
        'type'                 => ReceiptType::Payment,
        'transactionId'        => '01HTX',
        'gateway'              => 'fib',
        'reference'            => 'ord_1',
        'gatewayTransactionId' => 'pid_1',
        'status'               => PaymentStatus::Paid,
        'currency'             => Currency::IQD,
        'amountMinor'          => 5000,
        'amountFormatted'      => '5000',
        'refundedMinor'        => 0,
        'refundedFormatted'    => '0',
        'netMinor'             => 5000,
        'netFormatted'         => '5000',
        'isPartialRefund'      => false,
        'customerName'         => null,
        'customerEmail'        => null,
        'customerPhone'        => null,
        'issuedAt'             => new DateTimeImmutable(),
        'paidAt'               => null,
        'locale'               => 'en',
        'merchant'             => [],
        'metadata'             => [],
    ];

    return new ReceiptData(...array_merge($defaults, $overrides));
}

it('reports RTL only for Arabic and Kurdish locales', function () {
    expect(receiptData(['locale' => 'en'])->isRtl())->toBeFalse()
        ->and(receiptData(['locale' => 'ar'])->isRtl())->toBeTrue()
        ->and(receiptData(['locale' => 'ckb'])->isRtl())->toBeTrue();
});

it('exposes the currency symbol and refund flag', function () {
    expect(receiptData()->currencySymbol())->toBe('IQD')
        ->and(receiptData(['type' => ReceiptType::Refund])->isRefund())->toBeTrue()
        ->and(receiptData()->isRefund())->toBeFalse();
});

it('knows whether any customer detail is present', function () {
    expect(receiptData()->hasCustomer())->toBeFalse()
        ->and(receiptData(['customerPhone' => '0770'])->hasCustomer())->toBeTrue();
});
