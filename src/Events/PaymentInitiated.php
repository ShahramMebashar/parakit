<?php
declare(strict_types=1);

namespace Shah\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Shah\Parakit\DTOs\PaymentRequest;

class PaymentInitiated
{
    use Dispatchable;

    /**
     * Fires before the HTTP call to the gateway. No PaymentTransaction model
     * exists at this point — drivers persist the row only after the gateway
     * responds successfully. The caller's PaymentRequest is the only thing
     * available, so that's what we carry.
     */
    public function __construct(
        public readonly string $gateway,
        public readonly PaymentRequest $request,
    ) {}
}
