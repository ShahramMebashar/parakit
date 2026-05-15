<?php
declare(strict_types=1);

use Shah\Parakit\PaymentManager;
use Shah\Parakit\Contracts\PaymentGateway;
use Shah\Parakit\Exceptions\UnsupportedGatewayException;

it('resolves a driver by name from config', function () {
    config()->set('parakit.default', 'stub');
    config()->set('parakit.gateways.stub', ['driver' => 'stub']);

    $manager = app(PaymentManager::class);
    $manager->extend('stub', fn () => new class implements PaymentGateway {
        public function charge($r): \Shah\Parakit\DTOs\PaymentResponse { throw new RuntimeException('nope'); }
        public function handleWebhook(\Illuminate\Http\Request $r): \Shah\Parakit\DTOs\WebhookPayload { throw new RuntimeException('nope'); }
        public function name(): string { return 'stub'; }
    });

    expect($manager->driver()->name())->toBe('stub');
    expect($manager->driver('stub')->name())->toBe('stub');
});

it('throws for unknown gateway', function () {
    $manager = app(PaymentManager::class);
    $manager->driver('does_not_exist');
})->throws(UnsupportedGatewayException::class);

it('threads the configured gateway key into FibGateway::name (multi-config safety)', function () {
    config()->set('parakit.gateways.fib_branch_a', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD', 'callback_url' => 'https://app.test/cb',
    ]);

    $driver = app(PaymentManager::class)->driver('fib_branch_a');
    expect($driver->name())->toBe('fib_branch_a');
});

it('memoises resolved drivers (same instance returned)', function () {
    config()->set('parakit.gateways.stub', ['driver' => 'stub']);
    $manager = app(PaymentManager::class);
    $manager->extend('stub', function () {
        return new class implements PaymentGateway {
            public function charge($r): \Shah\Parakit\DTOs\PaymentResponse { throw new RuntimeException('nope'); }
            public function handleWebhook(\Illuminate\Http\Request $r): \Shah\Parakit\DTOs\WebhookPayload { throw new RuntimeException('nope'); }
            public function name(): string { return 'stub'; }
        };
    });

    expect($manager->driver('stub'))->toBe($manager->driver('stub'));
});
