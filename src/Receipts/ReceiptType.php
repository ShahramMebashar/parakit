<?php
declare(strict_types=1);

namespace Froshly\Parakit\Receipts;

enum ReceiptType: string
{
    case Payment = 'payment';
    case Refund = 'refund';

    /** The view suffix resolved as parakit::receipts.{template}.{viewKey}. */
    public function viewKey(): string
    {
        return $this->value;
    }
}
