<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

it('creates all three parakit tables when migrations run', function () {
    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasTable('payment_transactions'))->toBeTrue()
        ->and(Schema::hasTable('payment_webhook_events'))->toBeTrue()
        ->and(Schema::hasTable('payment_logs'))->toBeTrue();
});

it('enforces unique (gateway, event_id) on webhook events', function () {
    $this->artisan('migrate')->assertSuccessful();
    DB::table('payment_webhook_events')->insert([
        'gateway' => 'fib', 'event_id' => 'evt_1', 'status' => 'paid',
        'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => DB::table('payment_webhook_events')->insert([
        'gateway' => 'fib', 'event_id' => 'evt_1', 'status' => 'paid',
        'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
