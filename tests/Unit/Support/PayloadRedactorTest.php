<?php
declare(strict_types=1);

use Shah\Parakit\Support\PayloadRedactor;

it('redacts configured key names recursively', function () {
    $r = new PayloadRedactor(['password', 'secret', 'token']);
    $out = $r->redact([
        'user' => 'shah',
        'password' => 'p@ss',
        'auth' => ['token' => 'xyz', 'kept' => 'ok'],
    ]);
    expect($out['password'])->toBe('[REDACTED]')
        ->and($out['auth']['token'])->toBe('[REDACTED]')
        ->and($out['auth']['kept'])->toBe('ok');
});

it('redacts values matching credit-card-like patterns', function () {
    $r = new PayloadRedactor([]);
    $out = $r->redact(['note' => 'pay 4111111111111111 now']);
    expect($out['note'])->not->toContain('4111111111111111');
});

it('does not corrupt order references or Iraqi phone numbers that share digit lengths', function () {
    $r = new PayloadRedactor([]);
    $out = $r->redact([
        'reference' => 'ORDER-1234567890123',
        'phone' => '0790-123-4567',
        'long_ref' => '1234567890123456789',
    ]);
    expect($out['reference'])->toBe('ORDER-1234567890123')
        ->and($out['phone'])->toBe('0790-123-4567')
        ->and($out['long_ref'])->toBe('1234567890123456789');
});
