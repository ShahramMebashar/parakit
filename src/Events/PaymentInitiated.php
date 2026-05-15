<?php
declare(strict_types=1);

namespace Froshly\Parakit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Models\PaymentTransaction;

class PaymentInitiated
{
    use Dispatchable;

    /**
     * Fires after charge() has persisted the write-ahead PaymentTransaction
     * row (status Pending) and before the gateway HTTP call. The transaction
     * is available so listeners can link it to their own domain models.
     */
    public function __construct(
        public readonly string $gateway,
        public readonly PaymentRequest $request,
        public readonly PaymentTransaction $transaction,
    ) {}
}
