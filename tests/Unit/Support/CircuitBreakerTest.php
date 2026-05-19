<?php
declare(strict_types=1);

use Froshly\Parakit\Events\CircuitOpened;
use Froshly\Parakit\Support\CircuitBreaker;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => cache()->flush());

it('opens after threshold consecutive failures and reports open', function () {
    $cb = new CircuitBreaker('fib', threshold: 3, cooldownSeconds: 60);
    expect($cb->isOpen())->toBeFalse();
    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->isOpen())->toBeTrue();
});

it('resets the failure count on success', function () {
    $cb = new CircuitBreaker('fib', threshold: 3, cooldownSeconds: 60);
    $cb->recordFailure();
    $cb->recordFailure();
    $cb->recordSuccess();
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->isOpen())->toBeFalse();
});

it('closes after cooldown elapses', function () {
    $cb = new CircuitBreaker('fib', threshold: 2, cooldownSeconds: 1);
    $cb->recordFailure();
    $cb->recordFailure();
    expect($cb->isOpen())->toBeTrue();
    sleep(2);
    expect($cb->isOpen())->toBeFalse();
});

it('does not lose the first failure when the cache key did not previously exist', function () {
    cache()->flush();
    $cb = new CircuitBreaker('fib', threshold: 1, cooldownSeconds: 60);
    $cb->recordFailure();
    expect($cb->isOpen())->toBeTrue();
});

it('dispatches CircuitOpened once, on the failure that trips the breaker open', function () {
    Event::fake([CircuitOpened::class]);
    $cb = new CircuitBreaker('fib', threshold: 2, cooldownSeconds: 60);

    $cb->recordFailure();              // below threshold — no event
    Event::assertNotDispatched(CircuitOpened::class);

    $cb->recordFailure();              // trips open — one event
    $cb->recordFailure();              // already open — must not re-dispatch

    Event::assertDispatchedTimes(CircuitOpened::class, 1);
    Event::assertDispatched(CircuitOpened::class, fn (CircuitOpened $e) => $e->gateway === 'fib');
});
