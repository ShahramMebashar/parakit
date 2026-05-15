<?php
declare(strict_types=1);

namespace Shah\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Shah\Parakit\Models\PaymentTransaction;

class PaymentRefunded
{
    use Dispatchable;

    public function __construct(public readonly PaymentTransaction $transaction) {}
}
