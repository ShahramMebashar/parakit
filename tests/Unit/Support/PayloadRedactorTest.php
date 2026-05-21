<?php
declare(strict_types=1);

use Froshly\Parakit\Support\PayloadRedactor;

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

it('redacts gateway-specific credential keys via word-component matching', function () {
    $r = new PayloadRedactor(['password', 'token', 'secret', 'key']);
    $out = $r->redact([
        'store_password'    => 'fastpay-pw',
        'refund_secret_key' => 'rsk-123',
        'basic_token'       => 'b64-creds',
        'client_secret'     => 'oauth-secret',
        'api_key'           => 'ak_xxx',
        'public_key'        => '-----BEGIN PUBLIC KEY-----',
        'transactionPin'    => '1234',
        'storeId'           => 'STORE-1',
    ]);
    expect($out['store_password'])->toBe('[REDACTED]')
        ->and($out['refund_secret_key'])->toBe('[REDACTED]')
        ->and($out['basic_token'])->toBe('[REDACTED]')
        ->and($out['client_secret'])->toBe('[REDACTED]')
        ->and($out['api_key'])->toBe('[REDACTED]')
        ->and($out['public_key'])->toBe('[REDACTED]')
        ->and($out['storeId'])->toBe('STORE-1');
});

it('does not redact keys that merely contain a token as a substring (no false positives)', function () {
    $r = new PayloadRedactor(['key', 'card', 'secret', 'pin']);
    $out = $r->redact([
        'keyboard'    => 'shipped',
        'discard'     => 'reason',
        'wildcard'    => '*',
        'secretary'   => 'Alice',
        'spinner'     => 'on',
        'pinpoint'    => 'GPS-3',
    ]);
    foreach ($out as $v) {
        expect($v)->not->toBe('[REDACTED]');
    }
});
