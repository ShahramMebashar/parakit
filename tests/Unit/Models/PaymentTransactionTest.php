<?php
declare(strict_types=1);

use Shah\Parakit\Models\PaymentTransaction;
use Shah\Parakit\Enums\PaymentStatus;
use Shah\Parakit\Enums\Currency;

beforeEach(fn () => $this->artisan('migrate'));

it('persists with ULID and casts status/currency to enum', function () {
    $tx = PaymentTransaction::create([
        'gateway' => 'fib',
        'reference' => 'ord_1',
        'status' => PaymentStatus::Pending,
        'amount' => 5000,
        'currency' => Currency::IQD,
        'correlation_id' => '01H',
    ]);
    expect($tx->id)->toBeString()->and(strlen($tx->id))->toBe(26)
        ->and($tx->status)->toBe(PaymentStatus::Pending)
        ->and($tx->currency)->toBe(Currency::IQD);
});

it('transitions status via state machine helper and rejects illegal transitions', function () {
    $tx = PaymentTransaction::create([
        'gateway' => 'fib', 'reference' => 'ord_2',
        'status' => PaymentStatus::Paid, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => '01H',
    ]);
    expect($tx->transitionTo(PaymentStatus::Refunded))->toBeTrue();
    expect($tx->refresh()->status)->toBe(PaymentStatus::Refunded);

    expect(fn () => $tx->transitionTo(PaymentStatus::Pending))
        ->toThrow(\Shah\Parakit\Exceptions\IllegalStateTransitionException::class);
});

it('reports a no-op same-status transition as success without re-saving', function () {
    $tx = PaymentTransaction::create([
        'gateway' => 'fib', 'reference' => 'ord_3',
        'status' => PaymentStatus::Paid, 'amount' => 5000,
        'currency' => Currency::IQD, 'correlation_id' => '01H',
    ]);
    $originalPaidAt = $tx->paid_at;
    expect($tx->transitionTo(PaymentStatus::Paid))->toBeTrue();
    expect($tx->refresh()->paid_at?->toIso8601String())->toBe($originalPaidAt?->toIso8601String());
});
