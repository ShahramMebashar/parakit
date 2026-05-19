<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Facades\Payment;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    Http::fake([
        '*/api/v1/public/pgw/payment/initiation' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/FastPay/initiation_success.json'), true),
            200,
        ),
    ]);
});

/**
 * Two FastPay stores share the `fastpay` driver but carry distinct
 * credentials. Each resolved driver must send only its own store_id — proving
 * the driver reads credentials from injected config, never shared state
 * (the Octane / resolveMerchantUsing safety guarantee).
 */
it('keeps two FastPay stores isolated — each sends its own credentials', function () {
    config()->set('parakit.gateways.fastpay_a', [
        'driver' => 'fastpay', 'base_url' => 'https://staging-pgw.fast-pay.iq',
        'store_id' => 'STORE-A', 'store_password' => 'pw-a',
    ]);
    config()->set('parakit.gateways.fastpay_b', [
        'driver' => 'fastpay', 'base_url' => 'https://staging-pgw.fast-pay.iq',
        'store_id' => 'STORE-B', 'store_password' => 'pw-b',
    ]);

    Payment::driver('fastpay_a')->charge(new PaymentRequest(
        reference: 'INV-A', amount: 5000, currency: Currency::IQD, description: 'A',
    ));
    Payment::driver('fastpay_b')->charge(new PaymentRequest(
        reference: 'INV-B', amount: 7000, currency: Currency::IQD, description: 'B',
    ));

    $byStore = [];
    Http::assertSent(function ($req) use (&$byStore) {
        if (str_contains($req->url(), '/payment/initiation')) {
            $byStore[(string) $req['store_id']] = (string) $req['store_password'];
        }
        return true;
    });

    expect($byStore)->toBe(['STORE-A' => 'pw-a', 'STORE-B' => 'pw-b']);
});
