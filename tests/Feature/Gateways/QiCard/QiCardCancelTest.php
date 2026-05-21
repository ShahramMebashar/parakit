<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Froshly\Parakit\Contracts\SupportsCancel;
use Froshly\Parakit\Enums\PaymentStatus;
use Froshly\Parakit\Facades\Payment;

beforeEach(function () {
    Cache::flush();
    $this->artisan('migrate');
    config()->set('parakit.gateways.qicard', [
        'driver'      => 'qicard',
        'base_url'    => 'https://uat-sandbox-3ds-api.qi.iq',
        'username'    => 'u',
        'password'    => 'p',
        'terminal_id' => '237984',
    ]);
});

it('exposes SupportsCancel on the QiCard driver', function () {
    expect(Payment::driver('qicard'))->toBeInstanceOf(SupportsCancel::class);
});

it('cancels an unpaid payment and reports Cancelled', function () {
    Http::fake([
        '*/api/v1/payment/*/cancel' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/cancel_success.json'), true),
            200,
        ),
    ]);

    $r = Payment::driver('qicard')->cancel('f2bb43a8-488a-4281-977b-5b3418fc3c67');

    expect($r->status)->toBe(PaymentStatus::Cancelled)
        ->and($r->success)->toBeTrue();
});

it('sends a fresh requestId on the cancel call', function () {
    Http::fake([
        '*/api/v1/payment/*/cancel' => Http::response(
            json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/QiCard/cancel_success.json'), true),
            200,
        ),
    ]);

    Payment::driver('qicard')->cancel('f2bb43a8-488a-4281-977b-5b3418fc3c67');

    Http::assertSent(function ($req) {
        if (!str_contains($req->url(), '/cancel')) {
            return false;
        }
        $rid = $req->data()['requestId'] ?? '';
        return is_string($rid) && $rid !== '' && strlen($rid) <= 36;
    });
});

it('reports Cancelled when an earlier cancel attempt failed but the last one succeeded', function () {
    // QiCard preserves every cancel attempt in `cancels[]`. The last entry is
    // authoritative: a failed retry followed by a successful one must still
    // resolve to Cancelled.
    Http::fake([
        '*/api/v1/payment/*/cancel' => Http::response([
            'paymentId' => 'pid_1',
            'status'    => 'CREATED',
            'canceled'  => true,
            'amount'    => 5000,
            'currency'  => 'IQD',
            'cancels'   => [
                ['successfully' => false, 'created' => '2026-05-21T10:00:00Z'],
                ['successfully' => true,  'created' => '2026-05-21T10:01:00Z'],
            ],
        ], 200),
    ]);

    $r = Payment::driver('qicard')->cancel('pid_1');

    expect($r->status)->toBe(PaymentStatus::Cancelled)
        ->and($r->success)->toBeTrue();
});

it('does not report Cancelled when the most recent cancel attempt failed', function () {
    Http::fake([
        '*/api/v1/payment/*/cancel' => Http::response([
            'paymentId' => 'pid_1',
            'status'    => 'CREATED',
            'canceled'  => true,
            'amount'    => 5000,
            'currency'  => 'IQD',
            'cancels'   => [
                ['successfully' => true,  'created' => '2026-05-21T10:00:00Z'],
                ['successfully' => false, 'created' => '2026-05-21T10:01:00Z'],
            ],
        ], 200),
    ]);

    $r = Payment::driver('qicard')->cancel('pid_1');

    expect($r->status)->toBe(PaymentStatus::Pending)
        ->and($r->success)->toBeFalse();
});
