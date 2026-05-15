<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Shah\Parakit\Facades\Payment;
use Shah\Parakit\Enums\Currency;
use Shah\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    Cache::flush();
    config()->set('parakit.gateways.fib', [
        'driver' => 'fib',
        'base_url' => 'https://fib.stage.fib.iq',
        'client_id' => 'cid', 'client_secret' => 'csecret',
        'currency' => 'IQD', 'callback_url' => 'https://app.test/cb',
    ]);
});

it('charges via the fluent builder', function () {
    Http::fake([
        '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        '*/protected/v1/payments' => Http::response(['paymentId' => 'pid_x'], 200),
    ]);

    $order = (object) ['id' => 'ord_99'];

    $resp = Payment::for($order, 'id')
        ->driver('fib')
        ->amount(5000, Currency::IQD)
        ->description('Order #99')
        ->idempotencyKey('ord_99')
        ->charge();

    expect($resp->success)->toBeTrue()
        ->and($resp->gatewayTransactionId)->toBe('pid_x')
        ->and($resp->status)->toBe(PaymentStatus::Pending);
});

it('accepts a scalar reference and throws when amount is not set before charge()', function () {
    expect(fn () => Payment::for('ord_naked')->driver('fib')->charge())
        ->toThrow(\InvalidArgumentException::class);
});

it('throws when description is not set before charge()', function () {
    expect(fn () => Payment::for('ord_x')
        ->driver('fib')
        ->amount(5000, Currency::IQD)
        ->charge())->toThrow(\InvalidArgumentException::class);
});
