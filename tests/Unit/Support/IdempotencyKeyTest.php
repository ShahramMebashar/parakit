<?php
declare(strict_types=1);

use Froshly\Parakit\Support\IdempotencyKey;

it('hashes deterministically and is collision-resistant', function () {
    $a = IdempotencyKey::derive('fib', 'ord_1', 5000, 'IQD');
    $b = IdempotencyKey::derive('fib', 'ord_1', 5000, 'IQD');
    $c = IdempotencyKey::derive('fib', 'ord_2', 5000, 'IQD');
    expect($a)->toBe($b)->and($a)->not->toBe($c)->and(strlen($a))->toBe(64);
});
