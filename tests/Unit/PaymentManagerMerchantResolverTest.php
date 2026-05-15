<?php
declare(strict_types=1);

use Froshly\Parakit\PaymentManager;
use Froshly\Parakit\Contracts\PaymentGateway;
use Froshly\Parakit\DTOs\PaymentResponse;
use Froshly\Parakit\DTOs\WebhookPayload;
use Froshly\Parakit\Exceptions\UnsupportedGatewayException;

function makeStubGateway(string $label = 'stub'): PaymentGateway
{
    return new class ($label) implements PaymentGateway {
        public function __construct(private string $label) {}
        public function charge($r): PaymentResponse { throw new RuntimeException('nope'); }
        public function handleWebhook(\Illuminate\Http\Request $r): WebhookPayload { throw new RuntimeException('nope'); }
        public function name(): string { return $this->label; }
    };
}

beforeEach(function () {
    app(PaymentManager::class)->flushResolved();
});

it('calls resolver with gateway name and uses returned config to build driver', function () {
    $manager = app(PaymentManager::class);
    $calledWith = [];

    $manager->extend('stub', fn () => makeStubGateway());
    $manager->resolveMerchantUsing(function (string $name) use (&$calledWith): array {
        $calledWith[] = $name;
        return ['driver' => 'stub'];
    });

    $manager->driver('my_merchant');

    expect($calledWith)->toBe(['my_merchant']);
});

it('memoises within one request — same instance returned for repeated driver() calls', function () {
    $manager = app(PaymentManager::class);
    $created = 0;

    $manager->extend('stub', function () use (&$created) {
        $created++;
        return makeStubGateway();
    });
    $manager->resolveMerchantUsing(fn (string $name): array => ['driver' => 'stub']);

    $a = $manager->driver('merchant_x');
    $b = $manager->driver('merchant_x');

    expect($a)->toBe($b)
        ->and($created)->toBe(1);
});

it('after flushResolved() the next driver() call produces a new instance', function () {
    $manager = app(PaymentManager::class);
    $created = 0;

    $manager->extend('stub', function () use (&$created) {
        $created++;
        return makeStubGateway();
    });
    $manager->resolveMerchantUsing(fn (string $name): array => ['driver' => 'stub']);

    $before = $manager->driver('merchant_x');
    $manager->flushResolved();
    $after = $manager->driver('merchant_x');

    expect($before)->not->toBe($after)
        ->and($created)->toBe(2);
});

it('resolver is called with distinct names for distinct gateways — one tenant four gateways', function () {
    $manager = app(PaymentManager::class);
    $calledWith = [];

    $manager->extend('stub', fn () => makeStubGateway());
    $manager->resolveMerchantUsing(function (string $name) use (&$calledWith): array {
        $calledWith[] = $name;
        return ['driver' => 'stub'];
    });

    $manager->driver('fib');
    $manager->driver('zaincash');
    $manager->driver('fib_vip');
    $manager->driver('zaincash_premium');

    expect($calledWith)->toBe(['fib', 'zaincash', 'fib_vip', 'zaincash_premium']);
});

it('without resolver, memoisation still uses $resolved (existing behaviour unchanged)', function () {
    config()->set('parakit.gateways.stub', ['driver' => 'stub']);
    $manager = app(PaymentManager::class);
    $created = 0;

    $manager->extend('stub', function () use (&$created) {
        $created++;
        return makeStubGateway();
    });

    $a = $manager->driver('stub');
    $b = $manager->driver('stub');

    expect($a)->toBe($b)
        ->and($created)->toBe(1);
});

it('throws UnsupportedGatewayException when resolver returns empty array', function () {
    $manager = app(PaymentManager::class);
    $manager->resolveMerchantUsing(fn (string $name): array => []);
    $manager->driver('anything');
})->throws(UnsupportedGatewayException::class);
