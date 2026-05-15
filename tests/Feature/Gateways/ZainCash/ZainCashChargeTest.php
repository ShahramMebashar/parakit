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
        'driver'        => 'zaincash',
        'base_url'      => 'https://pg-api-uat.zaincash.iq',
        'client_id'     => 'cid',
        'client_secret' => 'csecret',
        'api_key'       => 'shared-secret-shared-secret-1234',
        'scope'         => 'payment:read payment:write reverse:write',
        'service_type'  => 'Delivery',
        'lang'          => 'en',
        'success_url'   => 'https://app.test/success',
        'failure_url'   => 'https://app.test/failure',
    ]);
});

function fakeZcInit(): void
{
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/init' => Http::response([
            'status' => 'SUCCESS',
            'transactionDetails' => [
                'transactionId' => 'zc_1',
                'orderId' => 'ord_1',
                'amount' => ['currency' => 'IQD', 'value' => 5000],
            ],
            'redirectUrl' => 'https://pg-api-uat.zaincash.iq/transaction/pay?id=zc_1&token=t',
            'expiryTime' => '2026-05-15T08:04:27.402+00:00',
        ], 200),
    ]);
}

it('creates a v2 transaction and returns the gateway redirect URL verbatim', function () {
    fakeZcInit();

    $r = Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_1',
        amount: 5000,
        currency: Currency::IQD,
        description: 'Order #1',
    ));

    expect($r->success)->toBeTrue()
        ->and($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->gatewayTransactionId)->toBe('zc_1')
        ->and($r->redirectUrl)->toBe('https://pg-api-uat.zaincash.iq/transaction/pay?id=zc_1&token=t');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/api/v2/payment-gateway/transaction/init')
        && $req['orderId'] === 'ord_1'
        && $req['language'] === 'En'
        && $req['serviceType'] === 'Delivery'
        && $req['amount']['value'] === '5000'
        && $req['amount']['currency'] === 'IQD'
        && $req['redirectUrls']['successUrl'] === 'https://app.test/success'
        && $req['redirectUrls']['failureUrl'] === 'https://app.test/failure'
        && is_string($req['externalReferenceId'])
        && preg_match('/^[0-9a-f-]{36}$/', $req['externalReferenceId']) === 1);
});

it('omits customer.phone when no phone is supplied and includes it when present', function () {
    fakeZcInit();

    Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_2', amount: 5000, currency: Currency::IQD, description: 'd',
        customerPhone: '9647801234567',
    ));

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/init')
        && ($req['customer']['phone'] ?? null) === '9647801234567');
});

it('overrides serviceType from request metadata', function () {
    fakeZcInit();

    Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_3', amount: 5000, currency: Currency::IQD, description: 'd',
        metadata: ['service_type' => 'Subscription'],
    ));

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/transaction/init')
        && $req['serviceType'] === 'Subscription');
});

it('reuses the same externalReferenceId across charge retries', function () {
    // First init fails -> AbstractGateway retries performCharge within the
    // same charge() call. Both attempts must send an identical
    // externalReferenceId so ZainCash collapses the duplicate.
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/init' => Http::sequence()
            ->push('boom', 500)
            ->push([
                'status' => 'SUCCESS',
                'transactionDetails' => [
                    'transactionId' => 'zc_1',
                    'orderId' => 'ord_4',
                    'amount' => ['currency' => 'IQD', 'value' => 5000],
                ],
                'redirectUrl' => 'https://pg-api-uat.zaincash.iq/transaction/pay?id=zc_1',
            ], 200),
    ]);

    Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_4', amount: 5000, currency: Currency::IQD, description: 'd',
    ));

    $seen = [];
    Http::assertSent(function ($request) use (&$seen) {
        if (str_contains($request->url(), '/transaction/init')) {
            $seen[] = $request['externalReferenceId'];
        }
        return true;
    });

    expect($seen)->toHaveCount(2)
        ->and($seen[0])->toBe($seen[1]);
});

it('fails when the init response lacks transactionId or redirectUrl', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 600]),
        '*/transaction/init' => Http::response(['status' => 'SUCCESS'], 200),
    ]);

    Payment::driver('zaincash')->charge(new PaymentRequest(
        reference: 'ord_5', amount: 5000, currency: Currency::IQD, description: 'd',
    ));
})->throws(\Froshly\Parakit\Exceptions\GatewayUnavailableException::class);
