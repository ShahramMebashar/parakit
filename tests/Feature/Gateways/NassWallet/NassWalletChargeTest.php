<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\DTOs\PaymentRequest;
use Froshly\Parakit\Enums\Currency;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Facades\Payment;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.nasswallet', [
        'driver'          => 'nasswallet',
        'base_url'        => 'https://uatgw1.nasswallet.com',
        'portal_url'      => 'https://uatcheckout1.nasswallet.com',
        'basic_token'     => 'BASIC_TOKEN',
        'username'        => '7500077974',
        'password'        => 'Nass@2020',
        'transaction_pin' => '135758',
        'callback_url'    => 'https://app.test/payments/webhooks/nasswallet',
    ]);
});

function fakeNwLogin(): array
{
    return [
        'responseCode' => 0, 'errCode' => '1', 'message' => 'Success',
        'data' => ['access_token' => 'tok_nw', 'accessTokenExpiry' => (string) ((time() + 3600) * 1000)],
    ];
}

function fakeNwCharge(): void
{
    Http::fake([
        '*/login' => Http::response(fakeNwLogin(), 200),
        '*/initTransaction' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/NassWallet/init_success.json'), true),
            200,
        ),
    ]);
}

it('charges via NassWallet and returns the checkout portal redirect url', function () {
    fakeNwCharge();

    $r = Payment::driver('nasswallet')->charge(new PaymentRequest(
        reference: 'INV-2026-ABC', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->gatewayTransactionId)->toBe('txn_399875107092750')
        ->and($r->redirectUrl)->toContain('uatcheckout1.nasswallet.com/payment-gateway')
        ->and($r->redirectUrl)->toContain('id=txn_399875107092750')
        ->and($r->redirectUrl)->toContain('token=8846')
        ->and($r->redirectUrl)->toContain('userIdentifier=7500077974');
});

it('sends a numeric orderId, a 2-decimal amount and the merchant credentials', function () {
    fakeNwCharge();

    Payment::driver('nasswallet')->charge(new PaymentRequest(
        reference: 'INV-2026-ABC', amount: 5000, currency: Currency::IQD, description: 'Order #1',
    ));

    Http::assertSent(function ($req) {
        if (!str_contains($req->url(), '/initTransaction')) {
            return true;
        }
        $data = $req['data'];

        return ctype_digit((string) $data['orderId'])
            && $data['amount'] === '5000.00'
            && $data['userIdentifier'] === '7500077974'
            && $data['transactionPin'] === '135758'
            && $data['languageCode'] === 'en';
    });
});

it('re-sends the same orderId when a charge attempt is retried', function () {
    Http::fake([
        '*/login' => Http::response(fakeNwLogin(), 200),
        '*/initTransaction' => Http::sequence()
            ->push('boom', 503)
            ->push(json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/NassWallet/init_success.json'), true), 200),
    ]);

    Payment::driver('nasswallet')->charge(new PaymentRequest(
        reference: 'INV-SAME', amount: 5000, currency: Currency::IQD, description: 'd',
    ));

    $seen = [];
    Http::assertSent(function ($req) use (&$seen) {
        if (str_contains($req->url(), '/initTransaction')) {
            $seen[] = (string) $req['data']['orderId'];
        }
        return true;
    });

    expect($seen)->toHaveCount(2)->and($seen[0])->toBe($seen[1]);
});

it('rejects a non-IQD charge — NassWallet settles IQD only', function () {
    fakeNwCharge();

    Payment::driver('nasswallet')->charge(new PaymentRequest(
        reference: 'INV-USD', amount: 5000, currency: Currency::USD, description: 'd',
    ));
})->throws(InvalidArgumentException::class);
