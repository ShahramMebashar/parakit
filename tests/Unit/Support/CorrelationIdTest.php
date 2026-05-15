<?php
declare(strict_types=1);

use Gutian\Parakit\Support\CorrelationId;

it('generates a 26-char ULID-like id', function () {
    $id = CorrelationId::generate();
    expect($id)->toBeString()->and(strlen($id))->toBe(26);
});

it('returns the bound id from the container if present', function () {
    app()->instance(CorrelationId::CONTEXT_KEY, 'fixed-id');
    expect(CorrelationId::current())->toBe('fixed-id');
});

it('reset() clears the bound id so the next current() generates a fresh one', function () {
    app()->instance(CorrelationId::CONTEXT_KEY, 'fixed-id');
    CorrelationId::reset();
    $fresh = CorrelationId::current();
    expect($fresh)->not->toBe('fixed-id')->and(strlen($fresh))->toBe(26);
});
