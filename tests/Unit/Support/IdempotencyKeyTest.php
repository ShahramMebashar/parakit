<?php
declare(strict_types=1);

use Froshly\Parakit\Support\IdempotencyKey;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;

it('hashes deterministically and is collision-resistant', function () {
    $a = IdempotencyKey::derive('fib', 'ord_1', 5000, 'IQD');
    $b = IdempotencyKey::derive('fib', 'ord_1', 5000, 'IQD');
    $c = IdempotencyKey::derive('fib', 'ord_2', 5000, 'IQD');
    expect($a)->toBe($b)->and($a)->not->toBe($c)->and(strlen($a))->toBe(64);
});

it('forGateway returns a 64-char hex digest', function () {
    $out = IdempotencyKey::forGateway('fastpay', 'order:123');
    expect(strlen($out))->toBe(64)
        ->and(ctype_xdigit($out))->toBeTrue();
});

it('forGateway is deterministic for the same gateway + key', function () {
    $a = IdempotencyKey::forGateway('fastpay', 'order:123');
    $b = IdempotencyKey::forGateway('fastpay', 'order:123');
    expect($a)->toBe($b);
});

it('forGateway namespaces by gateway so the same user key yields different digests', function () {
    $a = IdempotencyKey::forGateway('fastpay', 'order:123');
    $b = IdempotencyKey::forGateway('qicard', 'order:123');
    expect($a)->not->toBe($b);
});

it('forGateway accepts arbitrary user-supplied keys without throwing', function () {
    expect(IdempotencyKey::forGateway('nass', 'ORD-2026/ABC'))->toHaveLength(64)
        ->and(IdempotencyKey::forGateway('nass', 'k1'))->toHaveLength(64)
        ->and(IdempotencyKey::forGateway('nass', ''))->toHaveLength(64);
});

it('forGatewayOperation namespaces by operation', function () {
    $charge = IdempotencyKey::forGatewayOperation('qicard', 'charge', 'same-key');
    $refund = IdempotencyKey::forGatewayOperation('qicard', 'refund', 'same-key');

    expect($charge)->not->toBe($refund)
        ->and($refund)->toHaveLength(64)
        ->and(ctype_xdigit($refund))->toBeTrue();
});

it('derives gateway-safe ids from payment requests', function () {
    $request = new PaymentRequest('ord_1', 5000, Currency::IQD, 'desc', idempotencyKey: 'order:123');

    expect(IdempotencyKey::gatewayHexPrefixForRequest('fastpay', $request, 24))->toHaveLength(24)
        ->and(ctype_xdigit(IdempotencyKey::gatewayHexPrefixForRequest('fastpay', $request, 24)))->toBeTrue()
        ->and(IdempotencyKey::gatewayNumericForRequest('nass', $request))->toMatch('/^\d+$/');
});

it('builds cache keys without embedding the raw user key', function () {
    $cacheKey = IdempotencyKey::cacheKey('fastpay', 'charge', 'order:123/unsafe');

    expect($cacheKey)->toStartWith('parakit:charge:idem:')
        ->and($cacheKey)->not->toContain('order:123/unsafe');
});
