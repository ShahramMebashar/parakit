<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Froshly\Parakit\Facades\Payment;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.zaincash', [
        'driver' => 'zaincash',
        'base_url' => 'https://test.zaincash.iq',
        'merchant_id' => 'mer_1',
        'msisdn' => '07710000000',
        'secret' => 'shared-secret-shared-secret-1234',
        'lang' => 'en',
        'redirect_url' => 'https://app.test/return',
    ]);
});

it('inits a ZainCash transaction with a JWT and returns a hosted-page redirect', function () {
    Http::fake([
        '*/transaction/init' => Http::response(['id' => 'zc_1'], 200),
    ]);

    $r = Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_1',
        amount: 5000,
        currency: Currency::IQD,
        description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->gatewayTransactionId)->toBe('zc_1')
        ->and($r->redirectUrl)->toContain('/transaction/pay?id=zc_1');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/init')
        && is_string($req['token'])
        && substr_count($req['token'], '.') === 2);
});
