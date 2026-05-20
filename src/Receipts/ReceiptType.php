<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

/**
 * The kind of receipt being produced.
 *
 * The case value doubles as the Blade view suffix — a receipt renders
 * through parakit::receipts.{template}.{value}.
 */
enum ReceiptType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
}
