<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Shah\Parakit\Models\PaymentTransaction;
use Shah\Parakit\Enums\PaymentStatus;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Events\PaymentSucceeded;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD',
    ]);
    config()->set('parakit.sweeper.older_than_minutes', 0);
    config()->set('parakit.sweeper.max_age_hours', 24);
});

it('promotes stale pending transactions when status endpoint reports PAID', function () {
    Event::fake([PaymentSucceeded::class]);
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response([
            'status' => 'PAID',
            'amount' => ['amount' => '5000', 'currency' => 'IQD'],
        ], 200),
    ]);

    PaymentTransaction::create([
        'gateway' => 'fib', 'reference' => 'ord_1',
        'gateway_transaction_id' => 'pid_1',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);

    $this->artisan('parakit:sweep-pending')->assertSuccessful();
    expect(PaymentTransaction::first()->status)->toBe(PaymentStatus::Paid);
    Event::assertDispatched(PaymentSucceeded::class);
});

it('does not re-fire PaymentSucceeded when the row was already Paid (race-safe re-read)', function () {
    Event::fake([PaymentSucceeded::class]);
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments/*/status' => Http::response([
            'status' => 'PAID',
            'amount' => ['amount' => '5000', 'currency' => 'IQD'],
        ], 200),
    ]);

    // Cursor will read this row as Pending, but before the sweeper enters
    // its locked re-read another path has already moved it to Paid (the
    // "webhook arrived mid-sweep" race). Sweeper must re-read under lock
    // and detect no change, never firing the event twice.
    $tx = PaymentTransaction::create([
        'gateway' => 'fib', 'reference' => 'ord_race',
        'gateway_transaction_id' => 'pid_race',
        'status' => PaymentStatus::Pending, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => 'c',
    ]);
    $tx->transitionTo(PaymentStatus::Paid);

    $this->artisan('parakit:sweep-pending')->assertSuccessful();

    Event::assertNotDispatched(PaymentSucceeded::class);
});
