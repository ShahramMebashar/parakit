<?php
declare(strict_types=1);

use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\Contracts\PaymentGateway;

it('forwards resolveMerchantUsing through the facade', function () {
    $resolved = false;
    Payment::resolveMerchantUsing(function (string $name) use (&$resolved): array {
        $resolved = true;
        return ['driver' => 'stub'];
    });
    app('parakit.manager')->extend('stub', fn () => new class implements PaymentGateway {
        public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('nope'); }
        public function handleWebhook(\Illuminate\Http\Request $r): \Froshly\Parakit\DTOs\WebhookPayload { throw new RuntimeException('nope'); }
        public function name(): string { return 'stub'; }
    });

    Payment::driver('stub');

    expect($resolved)->toBeTrue();
});

it('resolves the manager via the facade and returns the right driver', function () {
    config()->set('parakit.gateways.stub', ['driver' => 'stub']);
    app('parakit.manager')->extend('stub', fn () => new class implements PaymentGateway {
        public function charge($r): \Froshly\Parakit\DTOs\PaymentResponse { throw new RuntimeException('nope'); }
        public function handleWebhook(\Illuminate\Http\Request $r): \Froshly\Parakit\DTOs\WebhookPayload { throw new RuntimeException('nope'); }
        public function name(): string { return 'stub'; }
    });

    expect(Payment::driver('stub')->name())->toBe('stub');
});
