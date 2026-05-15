<?php
declare(strict_types=1);

use Froshly\Parakit\Support\CircuitBreaker;

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
