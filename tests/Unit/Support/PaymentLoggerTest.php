<?php
declare(strict_types=1);

use Froshly\Parakit\Models\PaymentLog;
use Froshly\Parakit\Support\PaymentLogger;

beforeEach(fn () => $this->artisan('migrate'));

it('writes a redacted log row with duration', function () {
    config()->set('parakit.logging.redact_keys', ['secret']);
    $logger = app(PaymentLogger::class);

    $logger->record(
        action: 'charge',
        gateway: 'fib',
        endpoint: 'https://example/charge',
        statusCode: 200,
        durationMs: 42,
        request: ['amount' => 5000, 'secret' => 'shh'],
        response: ['id' => 'g_1'],
        correlationId: 'cid-1',
    );

    $row = PaymentLog::first();
    expect($row->correlation_id)->toBe('cid-1')
        ->and($row->action)->toBe('charge')
        ->and($row->duration_ms)->toBe(42)
        ->and($row->request['secret'])->toBe('[REDACTED]');
});

it('is a no-op when logging is disabled', function () {
    config()->set('parakit.logging.enabled', false);
    app(PaymentLogger::class)->record('charge', 'fib', null, null, null, [], [], 'cid');
    expect(PaymentLog::count())->toBe(0);
});
