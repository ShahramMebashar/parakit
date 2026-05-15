<?php
declare(strict_types=1);

namespace Gutian\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Gutian\Parakit\Models\PaymentTransaction;

class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public readonly PaymentTransaction $transaction) {}
}
