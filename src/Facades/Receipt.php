<?php
declare(strict_types=1);

namespace Froshly\Parakit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Froshly\Parakit\Receipts\ReceiptBuilder for(\Froshly\Parakit\Models\PaymentTransaction $transaction)
 * @method static \Froshly\Parakit\Receipts\ReceiptDocument generate(\Froshly\Parakit\Models\PaymentTransaction $transaction, \Froshly\Parakit\Receipts\ReceiptType $type = \Froshly\Parakit\Receipts\ReceiptType::Payment, \Froshly\Parakit\Receipts\CustomerDetails|array $customer = [], ?string $template = null, ?string $locale = null)
 * @method static string resolveTemplate(string $template)
 *
 * @see \Froshly\Parakit\Receipts\ReceiptManager
 */
class Receipt extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'parakit.receipts';
    }
}
