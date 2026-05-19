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
        '*/login' => Http::response([
            'responseCode' => 0, 'errCode' => '1', 'message' => 'Success',
            'data' => ['access_token' => 'tok', 'accessTokenExpiry' => (string) ((time() + 3600) * 1000)],
        ], 200),
        '*/initTransaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/NassWallet/init_success.json'), true),
            200,
        ),
    ]);
});

/**
 * Two NassWallet merchants share the `nasswallet` driver but carry distinct
 * credentials. Each resolved driver must authenticate with only its own
 * username — proving credentials come from injected config, never shared
 * state (the Octane / resolveMerchantUsing safety guarantee).
 */
it('keeps two NassWallet merchants isolated — each logs in with its own username', function () {
    config()->set('parakit.gateways.nasswallet_a', [
        'driver' => 'nasswallet', 'base_url' => 'https://uatgw1.nasswallet.com/payment/transaction',
        'portal_url' => 'https://uatcheckout1.nasswallet.com', 'basic_token' => 'BT',
        'username' => 'MERCHANT-A', 'password' => 'pw-a', 'transaction_pin' => '1111',
    ]);
    config()->set('parakit.gateways.nasswallet_b', [
        'driver' => 'nasswallet', 'base_url' => 'https://uatgw1.nasswallet.com/payment/transaction',
        'portal_url' => 'https://uatcheckout1.nasswallet.com', 'basic_token' => 'BT',
        'username' => 'MERCHANT-B', 'password' => 'pw-b', 'transaction_pin' => '2222',
    ]);

    Payment::driver('nasswallet_a')->charge(new PaymentRequest(
        reference: 'INV-A', amount: 5000, currency: Currency::IQD, description: 'A',
    ));
    Payment::driver('nasswallet_b')->charge(new PaymentRequest(
        reference: 'INV-B', amount: 7000, currency: Currency::IQD, description: 'B',
    ));

    $logins = [];
    Http::assertSent(function ($req) use (&$logins) {
        if (str_contains($req->url(), '/login')) {
            $logins[] = (string) $req['data']['username'];
        }
        return true;
    });

    expect($logins)->toContain('MERCHANT-A')
        ->and($logins)->toContain('MERCHANT-B');
});
