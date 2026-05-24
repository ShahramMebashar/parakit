<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Froshly\Parakit\Events\PaymentSucceeded;
use Froshly\Parakit\Events\PaymentFailed;
use Froshly\Parakit\Events\PaymentCancelled;
use Froshly\Parakit\Events\PaymentRefunded;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Enums\Currency;

beforeEach(fn () => $this->artisan('migrate'));

function makeEventTx(string $gateway = 'fib'): PaymentTransaction
{
    return PaymentTransaction::create([
        'gateway' => $gateway, 'reference' => 'r', 'status' => PaymentStatus::Paid,
        'amount' => 1, 'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);
}

it('dispatches PaymentSucceeded carrying the transaction', function () {
    Event::fake();
    $tx = makeEventTx();
    event(new PaymentSucceeded($tx));
    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->transaction->is($tx));
});

it('exposes the gateway name on every payment lifecycle event', function (string $class) {
    $tx = makeEventTx('zaincash');
    $event = new $class($tx);
    expect($event->gateway)->toBe('zaincash')
        ->and($event->transaction->is($tx))->toBeTrue();
})->with([
    PaymentSucceeded::class,
    PaymentFailed::class,
    PaymentCancelled::class,
    PaymentRefunded::class,
]);
