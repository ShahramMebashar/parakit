<?php
declare(strict_types=1);

use Gutian\Parakit\Models\PaymentLog;

beforeEach(fn () => $this->artisan('migrate'));

it('deletes payment_logs rows older than --days', function () {
    PaymentLog::create([
        'correlation_id' => 'c1', 'gateway' => 'fib', 'action' => 'charge',
        'created_at' => now()->subDays(200),
    ]);
    PaymentLog::create([
        'correlation_id' => 'c2', 'gateway' => 'fib', 'action' => 'charge',
        'created_at' => now()->subDays(10),
    ]);

    $this->artisan('parakit:logs:prune --days=90')->assertSuccessful();
    expect(PaymentLog::count())->toBe(1)
        ->and(PaymentLog::first()->correlation_id)->toBe('c2');
});
