<?php
declare(strict_types=1);

use Froshly\Parakit\Support\PersistedPayload;

it('redacts payloads before package-owned persistence by default', function () {
    $prepared = PersistedPayload::prepare([
        'access_token' => 'tok_secret',
        'redirect_uri' => 'https://gateway.test/pay?token=tok_in_url&id=pay_1',
        'customer' => ['msisdn' => '+9641000000004'],
    ]);

    $blob = json_encode($prepared, JSON_THROW_ON_ERROR);

    expect($blob)->not->toContain('tok_secret')
        ->and($blob)->not->toContain('tok_in_url')
        ->and($blob)->not->toContain('+9641000000004')
        ->and($blob)->toContain('[REDACTED]');
});

it('can disable package-owned raw payload storage', function () {
    config()->set('parakit.raw_payloads.store', false);

    expect(PersistedPayload::prepare(['token' => 'tok_secret']))->toBe([]);
});

it('can keep raw payloads when redaction is explicitly disabled', function () {
    config()->set('parakit.raw_payloads.redact', false);

    expect(PersistedPayload::prepare(['token' => 'tok_secret']))->toBe([
        'token' => 'tok_secret',
    ]);
});
