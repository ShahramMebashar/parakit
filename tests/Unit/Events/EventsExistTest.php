<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Gutian\Parakit\Events\PaymentSucceeded;
use Gutian\Parakit\Models\PaymentTransaction;
use Gutian\Parakit\Enums\PaymentStatus;
use Gutian\Parakit\Enums\Currency;

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
