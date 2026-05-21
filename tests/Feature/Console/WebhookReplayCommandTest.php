<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Events\PaymentSucceeded;
use Froshly\Parakit\Models\PaymentTransaction;
use Froshly\Parakit\Models\PaymentWebhookEvent;

beforeEach(fn () => $this->artisan('migrate'));

it('re-applies an orphaned event once the local transaction lands', function () {
    Event::fake([PaymentSucceeded::class]);

    PaymentTransaction::create([
        'gateway' => 'fib', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'gw_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    PaymentWebhookEvent::create([
        'gateway' => 'fib', 'event_id' => 'evt_1',
        'gateway_transaction_id' => 'gw_1', 'reference' => 'ord_1',
        'amount' => 5000, 'currency' => 'IQD',
        'status' => 'paid', 'payload' => [],
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('parakit:webhooks:replay', ['--older-than' => 5])
        ->expectsOutputToContain('1 applied')
        ->assertSuccessful();

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid)
        ->and(PaymentWebhookEvent::first()->processed_at)->not->toBeNull();
    Event::assertDispatched(PaymentSucceeded::class);
});

it('skips event rows that are newer than --older-than', function () {
    PaymentTransaction::create([
        'gateway' => 'fib', 'reference' => 'ord_2',
        'gateway_transaction_id' => 'gw_2',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    PaymentWebhookEvent::create([
        'gateway' => 'fib', 'event_id' => 'evt_2',
        'gateway_transaction_id' => 'gw_2', 'reference' => 'ord_2',
        'amount' => 5000, 'currency' => 'IQD',
        'status' => 'paid', 'payload' => [],
    ]);

    $this->artisan('parakit:webhooks:replay', ['--older-than' => 5])
        ->expectsOutputToContain('0 applied')
        ->assertSuccessful();

    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Pending);
});

it('skips pre-v0.9.1 rows that lack the gateway_transaction_id column', function () {
    PaymentWebhookEvent::create([
        'gateway' => 'fib', 'event_id' => 'evt_old',
        'status' => 'paid', 'payload' => [],
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('parakit:webhooks:replay', ['--older-than' => 5])
        ->expectsOutputToContain('0 applied')
        ->assertSuccessful();

    expect(PaymentWebhookEvent::first()->processed_at)->toBeNull();
});
