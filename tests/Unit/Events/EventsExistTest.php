<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Shah\Parakit\Events\PaymentSucceeded;
use Shah\Parakit\Models\PaymentTransaction;
use Shah\Parakit\Enums\PaymentStatus;
use Shah\Parakit\Enums\Currency;

beforeEach(fn () => $this->artisan('migrate'));

it('dispatches PaymentSucceeded carrying the transaction', function () {
    Event::fake();
    $tx = PaymentTransaction::create([
        'gateway' => 'fib', 'reference' => 'r', 'status' => PaymentStatus::Paid,
        'amount' => 1, 'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);
    event(new PaymentSucceeded($tx));
    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->transaction->is($tx));
});
